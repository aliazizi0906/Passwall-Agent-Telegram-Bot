package main

import (
	"bytes"
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

const failoverConfigPath = "/etc/pw-agent/failover.json"

type FailoverPolicy struct {
	Enabled             bool     `json:"enabled"`
	ShuntID             string   `json:"shunt_id"`
	PreferredNode       string   `json:"preferred_node"`
	AutoReturn          bool     `json:"auto_return"`
	IntervalSeconds     int      `json:"interval_seconds"`
	FailureThreshold    int      `json:"failure_threshold"`
	CooldownSeconds     int      `json:"cooldown_seconds"`
	MinImprovementMS    float64  `json:"min_improvement_ms"`
	ExcludedNodes       []string `json:"excluded_nodes,omitempty"`
	ConsecutiveFailures int      `json:"consecutive_failures"`
	LastCheckUnix       int64    `json:"last_check_unix"`
	LastSwitchUnix      int64    `json:"last_switch_unix"`
	LastSwitchFrom      string   `json:"last_switch_from,omitempty"`
	LastSwitchTo        string   `json:"last_switch_to,omitempty"`
	LastRelayMS         float64  `json:"last_relay_ms,omitempty"`
	LastError           string   `json:"last_error,omitempty"`
}

type FailoverStore struct {
	Policies map[string]*FailoverPolicy `json:"policies"`
}

var failoverMu sync.Mutex

func defaultFailoverPolicy(shuntID string) *FailoverPolicy {
	return &FailoverPolicy{
		Enabled: false, ShuntID: shuntID, AutoReturn: false,
		IntervalSeconds: 60, FailureThreshold: 2, CooldownSeconds: 180,
		MinImprovementMS: 75,
	}
}

func loadFailoverStore() (*FailoverStore, error) {
	failoverMu.Lock()
	defer failoverMu.Unlock()
	st := &FailoverStore{Policies: map[string]*FailoverPolicy{}}
	raw, err := os.ReadFile(failoverConfigPath)
	if errors.Is(err, os.ErrNotExist) {
		return st, nil
	}
	if err != nil {
		return nil, err
	}
	if len(bytes.TrimSpace(raw)) == 0 {
		return st, nil
	}
	if err := json.Unmarshal(raw, st); err != nil {
		return nil, err
	}
	if st.Policies == nil {
		st.Policies = map[string]*FailoverPolicy{}
	}
	return st, nil
}

func saveFailoverStore(st *FailoverStore) error {
	failoverMu.Lock()
	defer failoverMu.Unlock()
	if st.Policies == nil {
		st.Policies = map[string]*FailoverPolicy{}
	}
	raw, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(failoverConfigPath), 0700); err != nil {
		return err
	}
	tmp := failoverConfigPath + ".tmp"
	if err := os.WriteFile(tmp, raw, 0600); err != nil {
		return err
	}
	return os.Rename(tmp, failoverConfigPath)
}

func failoverSummaryV4() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	store, err := loadFailoverStore()
	if err != nil {
		return nil, err
	}
	names := map[string]string{"_direct": "Direct", "_default": "Use Default", "_blackhole": "Block"}
	for id, typ := range conf.SectionType {
		if typ == "nodes" {
			names[id] = displayNodeName(id, conf.Options[id])
		}
	}
	rows := []map[string]interface{}{}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && conf.Options[id]["protocol"] == "_shunt" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	for _, id := range ids {
		p := store.Policies[id]
		if p == nil {
			p = defaultFailoverPolicy(id)
		}
		cur := conf.Options[id]["default_node"]
		if cur == "" {
			cur = "_direct"
		}
		rows = append(rows, map[string]interface{}{
			"shunt_id":             id,
			"shunt_name":           displayNodeName(id, conf.Options[id]),
			"enabled":              p.Enabled,
			"preferred_node":       p.PreferredNode,
			"preferred_name":       names[p.PreferredNode],
			"current_node":         cur,
			"current_name":         names[cur],
			"auto_return":          p.AutoReturn,
			"interval_seconds":     p.IntervalSeconds,
			"failure_threshold":    p.FailureThreshold,
			"cooldown_seconds":     p.CooldownSeconds,
			"consecutive_failures": p.ConsecutiveFailures,
			"last_switch_unix":     p.LastSwitchUnix,
			"last_switch_from":     p.LastSwitchFrom,
			"last_switch_to":       p.LastSwitchTo,
			"last_error":           p.LastError,
			"last_relay_ms":        p.LastRelayMS,
		})
	}
	return map[string]interface{}{"policies": rows, "node_names": names}, nil
}

func failoverConfigureV4(params map[string]interface{}) (map[string]interface{}, error) {
	shuntID, err := stringParam(params, "shunt_id")
	if err != nil {
		return nil, err
	}
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if conf.SectionType[shuntID] != "nodes" || conf.Options[shuntID]["protocol"] != "_shunt" {
		return nil, errors.New("shunt node not found")
	}
	enabled, hasEnabled := params["enabled"].(bool)
	autoReturn, hasAutoReturn := params["auto_return"].(bool)
	store, err := loadFailoverStore()
	if err != nil {
		return nil, err
	}
	p := store.Policies[shuntID]
	if p == nil {
		p = defaultFailoverPolicy(shuntID)
	}
	wasEnabled := p.Enabled
	if hasEnabled {
		p.Enabled = enabled
	}
	if hasAutoReturn {
		p.AutoReturn = autoReturn
	}
	if v := int(int64Value(params["interval_seconds"])); v >= 20 && v <= 3600 {
		p.IntervalSeconds = v
	}
	if v := int(int64Value(params["failure_threshold"])); v >= 1 && v <= 10 {
		p.FailureThreshold = v
	}
	if v := int(int64Value(params["cooldown_seconds"])); v >= 30 && v <= 86400 {
		p.CooldownSeconds = v
	}
	if p.Enabled {
		cur := conf.Options[shuntID]["default_node"]
		if cur == "" {
			cur = "_direct"
		}
		if !isNormalProxyNodeV4(conf, cur) {
			return nil, errors.New("enable failover only when Default currently points to a normal proxy node")
		}
		if pref, _ := params["preferred_node"].(string); pref != "" {
			if !isNormalProxyNodeV4(conf, pref) {
				return nil, errors.New("preferred node is invalid")
			}
			p.PreferredNode = pref
		} else if (hasEnabled && p.Enabled && !wasEnabled) || p.PreferredNode == "" {
			p.PreferredNode = cur
		}
	}
	store.Policies[shuntID] = p
	if err := saveFailoverStore(store); err != nil {
		return nil, err
	}
	return failoverSummaryV4()
}

type relayRank struct {
	ID, Name string
	MS       float64
}

func isNormalProxyNodeV4(conf *UCIConfig, id string) bool {
	if conf.SectionType[id] != "nodes" {
		return false
	}
	proto := strings.TrimSpace(conf.Options[id]["protocol"])
	return proto != "" && !strings.HasPrefix(proto, "_")
}

func relayRankAllV4(conf *UCIConfig, excluded map[string]bool) []relayRank {
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && isNormalProxyNodeV4(conf, id) && !excluded[id] {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	sem := make(chan struct{}, 3)
	out := make(chan relayRank, len(ids))
	var wg sync.WaitGroup
	for _, id := range ids {
		id := id
		wg.Add(1)
		go func() {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			r := testRelay(id)
			if ok, _ := r["ok"].(bool); !ok {
				return
			}
			ms, ok := r["ms"].(float64)
			if !ok || ms <= 0 {
				return
			}
			out <- relayRank{ID: id, Name: displayNodeName(id, conf.Options[id]), MS: ms}
		}()
	}
	wg.Wait()
	close(out)
	rows := []relayRank{}
	for r := range out {
		rows = append(rows, r)
	}
	sort.Slice(rows, func(i, j int) bool {
		if rows[i].MS == rows[j].MS {
			return rows[i].Name < rows[j].Name
		}
		return rows[i].MS < rows[j].MS
	})
	return rows
}

func failoverRunOneV4(a *Agent, shuntID string, manual bool) (map[string]interface{}, error) {
	if !manual && !passwallRunning() {
		return map[string]interface{}{"skipped": true, "reason": "passwall2_not_running"}, nil
	}
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	store, err := loadFailoverStore()
	if err != nil {
		return nil, err
	}
	p := store.Policies[shuntID]
	if p == nil {
		return nil, errors.New("failover policy not configured")
	}
	if !p.Enabled && !manual {
		return map[string]interface{}{"skipped": true, "reason": "disabled"}, nil
	}
	if conf.SectionType[shuntID] != "nodes" || conf.Options[shuntID]["protocol"] != "_shunt" {
		return nil, errors.New("shunt node not found")
	}
	cur := conf.Options[shuntID]["default_node"]
	if cur == "" {
		cur = "_direct"
	}
	p.LastCheckUnix = time.Now().Unix()
	if !isNormalProxyNodeV4(conf, cur) {
		p.LastError = "current Default is not a testable proxy node"
		store.Policies[shuntID] = p
		_ = saveFailoverStore(store)
		return map[string]interface{}{"healthy": false, "current": cur, "detail": p.LastError}, nil
	}
	curHealth := scoreNodeV5(conf, cur)
	curRelay := map[string]interface{}{"ok": curHealth.OK, "ms": curHealth.RelayMS, "jitter_ms": curHealth.JitterMS, "loss": curHealth.Loss, "tcp_ok": curHealth.TCP, "score": curHealth.Score}
	if curHealth.OK {
		p.ConsecutiveFailures = 0
		p.LastError = ""
		p.LastRelayMS = curHealth.RelayMS
		// Optional return to the preferred node after cooldown.
		if p.AutoReturn && p.PreferredNode != "" && cur != p.PreferredNode && time.Now().Unix()-p.LastSwitchUnix >= int64(p.CooldownSeconds) {
			if isNormalProxyNodeV4(conf, p.PreferredNode) {
				rr := scoreNodeV5(conf, p.PreferredNode)
				if rr.OK {
					old := cur
					if _, err := setShuntDefaultV3(shuntID, p.PreferredNode); err == nil {
						p.LastSwitchUnix = time.Now().Unix()
						p.LastSwitchFrom = old
						p.LastSwitchTo = p.PreferredNode
						store.Policies[shuntID] = p
						_ = saveFailoverStore(store)
						if a != nil {
							_ = a.emitEventV4("failover.return", map[string]interface{}{"shunt_id": shuntID, "from": old, "to": p.PreferredNode, "to_name": displayNodeName(p.PreferredNode, conf.Options[p.PreferredNode])})
						}
						return map[string]interface{}{"healthy": true, "switched": true, "reason": "preferred_recovered", "from": old, "to": p.PreferredNode}, nil
					}
				}
			}
		}
		store.Policies[shuntID] = p
		_ = saveFailoverStore(store)
		return map[string]interface{}{"healthy": true, "current": cur, "relay": curRelay, "failures": 0}, nil
	}
	p.ConsecutiveFailures++
	p.LastError = "HTTP relay test failed"
	store.Policies[shuntID] = p
	_ = saveFailoverStore(store)
	if !manual && p.ConsecutiveFailures < p.FailureThreshold {
		return map[string]interface{}{"healthy": false, "current": cur, "failures": p.ConsecutiveFailures, "threshold": p.FailureThreshold, "switched": false}, nil
	}
	if !manual && p.LastSwitchUnix > 0 && time.Now().Unix()-p.LastSwitchUnix < int64(p.CooldownSeconds) {
		return map[string]interface{}{"healthy": false, "current": cur, "failures": p.ConsecutiveFailures, "switched": false, "reason": "cooldown"}, nil
	}
	excluded := map[string]bool{cur: true}
	for _, id := range p.ExcludedNodes {
		excluded[id] = true
	}
	ranks := relayRankAllV5(conf, excluded)
	if len(ranks) == 0 {
		if a != nil {
			_ = a.emitEventV4("failover.no_candidate", map[string]interface{}{"shunt_id": shuntID, "current": cur})
		}
		return map[string]interface{}{"healthy": false, "current": cur, "failures": p.ConsecutiveFailures, "switched": false, "reason": "no healthy relay candidate"}, nil
	}
	best := ranks[0]
	old := cur
	if _, err := setShuntDefaultV3(shuntID, best.ID); err != nil {
		return nil, err
	}
	p.ConsecutiveFailures = 0
	p.LastSwitchUnix = time.Now().Unix()
	p.LastSwitchFrom = old
	p.LastSwitchTo = best.ID
	p.LastRelayMS = best.MS
	p.LastError = ""
	store.Policies[shuntID] = p
	_ = saveFailoverStore(store)
	if a != nil {
		_ = a.emitEventV4("failover.switch", map[string]interface{}{"shunt_id": shuntID, "from": old, "to": best.ID, "to_name": best.Name, "relay_ms": best.MS})
	}
	top := []map[string]interface{}{}
	for i, r := range ranks {
		if i >= 5 {
			break
		}
		top = append(top, map[string]interface{}{"id": r.ID, "name": r.Name, "relay_ms": r.MS})
	}
	return map[string]interface{}{"healthy": false, "switched": true, "from": old, "to": best.ID, "to_name": best.Name, "relay_ms": best.MS, "ranking": top}, nil
}

func (a *Agent) failoverLoopV4() {
	time.Sleep(20 * time.Second)
	for {
		store, err := loadFailoverStore()
		if err == nil {
			now := time.Now().Unix()
			ids := []string{}
			for id, p := range store.Policies {
				if p != nil && p.Enabled && now-p.LastCheckUnix >= int64(maxIntV4(p.IntervalSeconds, 20)) {
					ids = append(ids, id)
				}
			}
			sort.Strings(ids)
			for _, id := range ids {
				_, _ = failoverRunOneV4(a, id, false)
			}
		}
		time.Sleep(15 * time.Second)
	}
}

func maxIntV4(a, b int) int {
	if a > b {
		return a
	}
	return b
}

func (a *Agent) emitEventV4(kind string, data map[string]interface{}) error {
	payload := map[string]interface{}{"kind": kind, "data": data, "hostname": hostname(), "agent_version": agentVersion}
	_, _, err := a.signedPOST(a.cfg.ServerURL+"/event.php", payload)
	return err
}

func listSubscriptionsV4() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	rows := []map[string]interface{}{}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "subscribe_list" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	for _, id := range ids {
		o := conf.Options[id]
		count := 0
		for nid, typ := range conf.SectionType {
			if typ == "nodes" && strings.EqualFold(strings.TrimSpace(conf.Options[nid]["group"]), strings.TrimSpace(o["remark"])) {
				count++
			}
		}
		rows = append(rows, map[string]interface{}{"id": id, "remark": o["remark"], "url": o["url"], "node_count": count, "expired_date": o["expired_date"], "rem_traffic": o["rem_traffic"]})
	}
	return map[string]interface{}{"subscriptions": rows}, nil
}

func addSubscriptionV4(url, remark string) (map[string]interface{}, error) {
	url = strings.TrimSpace(url)
	remark = strings.TrimSpace(remark)
	if len(url) < 8 || len(url) > 4096 || !(strings.HasPrefix(strings.ToLower(url), "https://") || strings.HasPrefix(strings.ToLower(url), "http://")) {
		return nil, errors.New("subscription URL must be http(s)")
	}
	if remark == "" || len([]byte(remark)) > 80 {
		return nil, errors.New("subscription name must be 1-80 bytes")
	}
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	for id, typ := range conf.SectionType {
		if typ == "subscribe_list" && strings.EqualFold(strings.TrimSpace(conf.Options[id]["remark"]), remark) {
			return nil, errors.New("subscription name already exists")
		}
	}
	if strings.EqualFold(remark, "default") {
		return nil, errors.New("subscription name cannot be default")
	}
	b := make([]byte, 4)
	if _, err := io.ReadFull(randReaderV4{}, b); err != nil {
		return nil, err
	}
	id := "sub_tg_" + hex.EncodeToString(b)
	uci := commandPathRequired("uci")
	cmds := [][]string{{"set", "passwall2." + id + "=subscribe_list"}, {"set", "passwall2." + id + ".remark=" + remark}, {"set", "passwall2." + id + ".url=" + url}}
	for _, args := range cmds {
		if _, err := runOutput(10*time.Second, uci, args...); err != nil {
			return nil, err
		}
	}
	if _, err := runOutput(10*time.Second, uci, "commit", "passwall2"); err != nil {
		return nil, err
	}
	if _, err := runOutput(180*time.Second, commandPathRequired("lua"), "/usr/share/passwall2/subscribe.lua", "start", id, "manual"); err != nil {
		return nil, fmt.Errorf("subscription created but update failed: %w", err)
	}
	return listSubscriptionsV4()
}

// randReaderV4 avoids an extra import collision with crypto/rand in this file.
type randReaderV4 struct{}

func (randReaderV4) Read(p []byte) (int, error) {
	f, err := os.Open("/dev/urandom")
	if err != nil {
		return 0, err
	}
	defer f.Close()
	return io.ReadFull(f, p)
}

func updateSubscriptionV4(id string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if id != "all" {
		if !safeID.MatchString(id) || conf.SectionType[id] != "subscribe_list" {
			return nil, errors.New("subscription not found")
		}
	}
	if _, err := runOutput(240*time.Second, commandPathRequired("lua"), "/usr/share/passwall2/subscribe.lua", "start", id, "manual"); err != nil {
		return nil, err
	}
	return listSubscriptionsV4()
}

func deleteSubscriptionV4(id string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if !safeID.MatchString(id) || conf.SectionType[id] != "subscribe_list" {
		return nil, errors.New("subscription not found")
	}
	remark := conf.Options[id]["remark"]
	if _, err := backupPasswall(); err != nil {
		return nil, err
	}
	// Remove subscription-owned nodes safely. Referenced nodes are preserved instead of breaking Shunt/other config.
	removed := 0
	skipped := 0
	groupIDs := []string{}
	for nid, typ := range conf.SectionType {
		if typ == "nodes" && strings.EqualFold(strings.TrimSpace(conf.Options[nid]["group"]), strings.TrimSpace(remark)) {
			groupIDs = append(groupIDs, nid)
		}
	}
	sort.Strings(groupIDs)
	for _, nid := range groupIDs {
		if _, err := deleteNode(nid); err != nil {
			skipped++
		} else {
			removed++
		}
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "delete", "passwall2."+id); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	result, err := listSubscriptionsV4()
	if err != nil {
		return nil, err
	}
	result["removed_nodes"] = removed
	result["skipped_referenced_nodes"] = skipped
	return result, nil
}

func relayRankingV4() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	ranks := relayRankAllV4(conf, map[string]bool{})
	rows := []map[string]interface{}{}
	for _, r := range ranks {
		rows = append(rows, map[string]interface{}{"id": r.ID, "remarks": r.Name, "relay_ms": r.MS})
	}
	return map[string]interface{}{"ranking": rows, "healthy_count": len(rows)}, nil
}

func deadNodeCandidatesV4() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && isNormalProxyNodeV4(conf, id) {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	type scanResult struct {
		id, name string
		relay    map[string]interface{}
		ok       bool
	}
	sem := make(chan struct{}, 3)
	out := make(chan scanResult, len(ids))
	var wg sync.WaitGroup
	for _, id := range ids {
		id := id
		wg.Add(1)
		go func() {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			r := testRelay(id)
			ok, _ := r["ok"].(bool)
			out <- scanResult{id: id, name: displayNodeName(id, conf.Options[id]), relay: r, ok: ok}
		}()
	}
	wg.Wait()
	close(out)
	dead := []map[string]interface{}{}
	alive := 0
	for r := range out {
		if r.ok {
			alive++
		} else {
			dead = append(dead, map[string]interface{}{"id": r.id, "remarks": r.name, "relay": r.relay})
		}
	}
	sort.Slice(dead, func(i, j int) bool { return fmt.Sprint(dead[i]["remarks"]) < fmt.Sprint(dead[j]["remarks"]) })
	return map[string]interface{}{"dead": dead, "dead_count": len(dead), "alive_count": alive, "total": len(ids)}, nil
}

func deleteNodesManyV4(ids []string) (map[string]interface{}, error) {
	deleted := []string{}
	skipped := []map[string]interface{}{}
	seen := map[string]bool{}
	for _, id := range ids {
		if seen[id] {
			continue
		}
		seen[id] = true
		if !safeID.MatchString(id) {
			skipped = append(skipped, map[string]interface{}{"id": id, "error": "invalid id"})
			continue
		}
		r, err := deleteNode(id)
		if err != nil {
			e := map[string]interface{}{"id": id, "error": err.Error()}
			if r != nil {
				for k, v := range r {
					e[k] = v
				}
			}
			skipped = append(skipped, e)
			continue
		}
		deleted = append(deleted, id)
	}
	return map[string]interface{}{"deleted": deleted, "deleted_count": len(deleted), "skipped": skipped, "skipped_count": len(skipped)}, nil
}

func deleteAllNodesV4() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && isNormalProxyNodeV4(conf, id) {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	return deleteNodesManyV4(ids)
}

func pruneDeadNodesV4() (map[string]interface{}, error) {
	scan, err := deadNodeCandidatesV4()
	if err != nil {
		return nil, err
	}
	ids := []string{}
	for _, x := range scan["dead"].([]map[string]interface{}) {
		ids = append(ids, x["id"].(string))
	}
	del, err := deleteNodesManyV4(ids)
	if err != nil {
		return nil, err
	}
	del["scan"] = scan
	return del, nil
}

func (a *Agent) agentUpdateCheckV4() (map[string]interface{}, error) {
	raw, _, err := a.signedPOST(a.cfg.ServerURL+"/update_manifest.php", map[string]interface{}{"current_version": agentVersion, "arch": agentArchV5()})
	if err != nil {
		return nil, err
	}
	var out map[string]interface{}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, err
	}
	return out, nil
}

func (a *Agent) agentUpdateApplyV4() (map[string]interface{}, error) {
	raw, _, err := a.signedPOST(a.cfg.ServerURL+"/update_download.php", map[string]interface{}{"current_version": agentVersion, "arch": agentArchV5()})
	if err != nil {
		return nil, err
	}
	var out map[string]interface{}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, err
	}
	if ok, _ := out["ok"].(bool); !ok {
		return nil, errors.New("update server returned ok=false")
	}
	data, ok := out["data_b64"].(string)
	if !ok || data == "" {
		return nil, errors.New("update package missing")
	}
	rawPkg, err := base64.StdEncoding.DecodeString(data)
	if err != nil {
		return nil, errors.New("invalid update package encoding")
	}
	if len(rawPkg) < 1024 || len(rawPkg) > 16<<20 {
		return nil, errors.New("update package size is invalid")
	}
	sum := sha256.Sum256(rawPkg)
	actual := hex.EncodeToString(sum[:])
	expected, _ := out["sha256"].(string)
	if expected == "" || !strings.EqualFold(expected, actual) {
		return nil, errors.New("update package checksum mismatch")
	}
	signature, _ := out["signature_b64"].(string)
	if signature == "" {
		return nil, errors.New("update package signature missing")
	}
	if err := a.verifyUpdateSignatureV5(rawPkg, signature); err != nil {
		return nil, err
	}
	dir := "/tmp/pw-agent-update"
	_ = os.RemoveAll(dir)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return nil, err
	}
	pkg := dir + "/bundle.tar.gz"
	if err := os.WriteFile(pkg, rawPkg, 0600); err != nil {
		return nil, err
	}
	tar := commandPath("tar")
	if tar == "" {
		return nil, errors.New("tar unavailable")
	}
	if _, err := runOutput(60*time.Second, tar, "-xzf", pkg, "-C", dir); err != nil {
		return nil, err
	}
	script := dir + "/install-update.sh"
	if _, err := os.Stat(script); err != nil {
		return nil, errors.New("update installer missing")
	}
	_ = os.Chmod(script, 0700)
	cmd := exec.Command("/bin/sh", "-c", "sleep 4; "+script+" >/tmp/pw-agent-update.log 2>&1")
	cmd.Stdin = nil
	cmd.Stdout = nil
	cmd.Stderr = nil
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return map[string]interface{}{"scheduled": true, "version": out["version"], "sha256": actual, "message": "update staged; agent will restart automatically"}, nil
}

func (a *Agent) diagnoseV4() map[string]interface{} {
	out := map[string]interface{}{"ok": false, "agent_version": agentVersion, "device_id": a.cfg.DeviceID, "server_url": a.cfg.ServerURL, "hostname": hostname()}
	start := time.Now()
	raw, _, err := a.signedPOST(a.cfg.ServerURL+"/diagnose.php", map[string]interface{}{"agent_version": agentVersion, "hostname": hostname()})
	out["latency_ms"] = float64(time.Since(start).Microseconds()) / 1000
	if err != nil {
		out["error"] = err.Error()
		return out
	}
	var srv map[string]interface{}
	if err := json.Unmarshal(raw, &srv); err != nil {
		out["error"] = "invalid server response"
		return out
	}
	out["server"] = srv
	out["ok"] = true
	out["auth_ok"] = true
	out["response_signature_ok"] = true
	return out
}

func (a *Agent) terminalExecV4(params map[string]interface{}) (map[string]interface{}, error) {
	sealed, err := stringParam(params, "sealed")
	if err != nil {
		return nil, errors.New("encrypted terminal payload required")
	}
	packed, err := base64.StdEncoding.DecodeString(sealed)
	if err != nil || len(packed) < 12+16+1 {
		return nil, errors.New("invalid encrypted terminal payload")
	}
	key := sha256.Sum256([]byte(a.cfg.DeviceSecret))
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	nonce := packed[:12]
	tag := packed[12:28]
	ciphertext := packed[28:]
	combined := append(append([]byte{}, ciphertext...), tag...)
	plain, err := gcm.Open(nil, nonce, combined, nil)
	if err != nil {
		return nil, errors.New("terminal payload authentication failed")
	}
	var secretParams map[string]interface{}
	if err := json.Unmarshal(plain, &secretParams); err != nil {
		return nil, errors.New("invalid terminal payload")
	}
	for i := range plain {
		plain[i] = 0
	}
	user, err := stringParam(secretParams, "username")
	if err != nil {
		return nil, err
	}
	password, err := stringParam(secretParams, "password")
	if err != nil {
		return nil, err
	}
	command, err := stringParam(secretParams, "command")
	if err != nil {
		return nil, err
	}
	if !regexp.MustCompile(`^[A-Za-z0-9._-]{1,32}$`).MatchString(user) {
		return nil, errors.New("invalid SSH username")
	}
	if len(password) > 256 {
		return nil, errors.New("password too long")
	}
	if len(command) > 4096 {
		return nil, errors.New("command too long")
	}
	cwd := "/root"
	if v, ok := secretParams["cwd"].(string); ok && strings.HasPrefix(v, "/") && len(v) < 512 {
		cwd = v
	}
	dbclient := commandPath("dbclient")
	if dbclient == "" {
		dbclient = commandPath("ssh")
	}
	if dbclient == "" {
		return nil, errors.New("Dropbear dbclient/ssh client is unavailable")
	}
	port := "22"
	if p, e := uciGet("dropbear.@dropbear[0].Port"); e == nil && regexp.MustCompile(`^[0-9]{1,5}$`).MatchString(p) {
		port = p
	}
	marker := "__PWTERM_" + strconv.FormatInt(time.Now().UnixNano(), 36)
	wrapped := "cd " + shellQuoteV4(cwd) + " 2>/dev/null || cd /root; { " + command + "; }; rc=$?; printf '\\n" + marker + "_RC=%s\\n" + marker + "_CWD=' \"$rc\"; pwd"
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, dbclient, "-y", "-T", "-p", port, user+"@127.0.0.1", wrapped)
	cmd.Env = append(os.Environ(), "DROPBEAR_PASSWORD="+password)
	cmd.Stdin = nil
	raw, runErr := cmd.CombinedOutput()
	if ctx.Err() == context.DeadlineExceeded {
		return nil, errors.New("SSH command timed out")
	}
	text := string(raw)
	if len(text) > 65536 {
		text = text[len(text)-65536:]
	}
	if runErr != nil && !strings.Contains(text, marker+"_RC=") {
		return nil, errors.New("SSH authentication/connection failed: " + sanitizeTerminalErrorV4(text))
	}
	rc := 0
	newCwd := cwd
	outText := text
	if i := strings.LastIndex(text, "\n"+marker+"_RC="); i >= 0 {
		outText = text[:i]
		meta := text[i+1:]
		lines := strings.Split(meta, "\n")
		for _, ln := range lines {
			if strings.HasPrefix(ln, marker+"_RC=") {
				rc, _ = strconv.Atoi(strings.TrimPrefix(ln, marker+"_RC="))
			} else if strings.HasPrefix(ln, marker+"_CWD=") {
				newCwd = strings.TrimPrefix(ln, marker+"_CWD=")
			}
		}
	}
	return map[string]interface{}{"output": strings.TrimRight(outText, "\r\n"), "exit_code": rc, "cwd": strings.TrimSpace(newCwd)}, nil
}

func shellQuoteV4(s string) string { return "'" + strings.ReplaceAll(s, "'", "'\\''") + "'" }
func sanitizeTerminalErrorV4(s string) string {
	s = strings.TrimSpace(s)
	if len(s) > 300 {
		s = s[:300]
	}
	return s
}

// CLI helper used by the LuCI page. It tests configuration, HMAC authentication,
// server reachability, and response-signature verification without leasing a command.
func runCLIIfRequestedV4() bool {
	if len(os.Args) < 2 {
		return false
	}
	if os.Args[1] == "--version" || os.Args[1] == "version" {
		fmt.Println(agentVersion)
		return true
	}
	// Local read-only operations work without a PHP backend or Telegram connectivity.
	// LuCI rpcd ACL explicitly grants each subcommand; no arbitrary exec surface.
	if len(os.Args) == 2 {
		var value interface{}
		var execErr error
		switch os.Args[1] {
		case "capabilities-json":
			value = capabilityReportV13()
		case "cellular-json":
			value, execErr = cellularStatusV14()
		case "network-policies-json":
			value, execErr = networkPoliciesV14()
		case "led-inventory-json":
			value, execErr = ledInventoryV14()
		case "led-json":
			value, execErr = ledStatusV13()
		case "network-doctor-json":
			value, execErr = networkDoctorV13()
		case "clients-json":
			value, execErr = connectedClients()
		case "wifi-json":
			value, execErr = listWiFi()
		case "status-json":
			value, execErr = systemStatus()
		}
		if value != nil || execErr != nil {
			if execErr != nil {
				value = map[string]interface{}{"ok": false, "error": execErr.Error()}
			}
			output, encodeErr := json.Marshal(value)
			if encodeErr != nil {
				output = []byte(`{"ok":false,"error":"serialization failed"}`)
			}
			fmt.Println(string(output))
			return true
		}
	}
	if os.Args[1] != "diagnose-json" {
		return false
	}
	cfgPath := "/etc/pw-agent/config.json"
	if v := os.Getenv("PW_AGENT_CONFIG"); v != "" {
		cfgPath = v
	}
	raw, err := os.ReadFile(cfgPath)
	if err != nil {
		b, _ := json.Marshal(map[string]interface{}{"ok": false, "error": err.Error()})
		fmt.Println(string(b))
		return true
	}
	var cfg Config
	if err := json.Unmarshal(raw, &cfg); err != nil {
		b, _ := json.Marshal(map[string]interface{}{"ok": false, "error": err.Error()})
		fmt.Println(string(b))
		return true
	}
	cfg.ServerURL = strings.TrimRight(cfg.ServerURL, "/")
	if cfg.RequestTimeoutSec < 5 {
		cfg.RequestTimeoutSec = 15
	}
	a := &Agent{cfg: cfg, client: &http.Client{Timeout: time.Duration(cfg.RequestTimeoutSec) * time.Second}}
	b, _ := json.Marshal(a.diagnoseV4())
	fmt.Println(string(b))
	return true
}
