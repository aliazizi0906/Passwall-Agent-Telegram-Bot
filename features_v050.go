package main

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"math"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"sync"
	"time"
)

type idempotencyRecordV5 struct {
	Action      string          `json:"action"`
	Success     bool            `json:"success"`
	Result      json.RawMessage `json:"result,omitempty"`
	Error       string          `json:"error,omitempty"`
	CompletedAt int64           `json:"completed_at"`
}

type idempotencyStoreV5 struct {
	Records map[string]idempotencyRecordV5 `json:"records"`
}

const officialUpdatePublicKeyV5 = "fDiTaZG26qn3BCI3c8cFiO0KdqMW561w6JSaMwXCecw="

var idempotencyMuV5 sync.Mutex

func idempotencyPathV5() string {
	if p := os.Getenv("PW_AGENT_IDEMPOTENCY_PATH"); p != "" {
		return p
	}
	return "/etc/pw-agent/idempotency.json"
}

func isMutationActionV5(action string) bool {
	switch action {
	case "system.backup.restore", "system.reboot",
		"wifi.set_ssid", "wifi.set_password", "wifi.reload", "wifi.radio.toggle", "wifi.radio.channel",
		"passwall2.enable", "passwall2.disable", "passwall2.restart", "passwall2.node.select",
		"passwall2.node.add_manual", "passwall2.node.add_link", "passwall2.node.delete",
		"passwall2.shunt.set", "passwall2.shunt.set_default", "passwall2.failover.configure",
		"passwall2.subscription.add", "passwall2.subscription.update", "passwall2.subscription.delete",
		"passwall2.nodes.prune_dead", "passwall2.nodes.delete_many", "passwall2.nodes.delete_all",
		"system.service.restart", "network.wan.reconnect", "firewall.redirect.toggle",
		"agent.update.apply", "terminal.exec", "config.rollback_snapshot",
		"led.brightness.set", "openvpn.service.control",
		"wifi.ssid.safe_apply", "wifi.ssid.safe_confirm",
		"openvpn.profile.toggle", "pbr.policy.add", "pbr.policy.delete",
		"clients.internet.block", "sqm.queue.set", "wifi.channel.safe_apply",
		"firewall.wan_guard.set", "config.safe.confirm":
		return true
	default:
		return false
	}
}

func loadIdempotencyStoreV5() idempotencyStoreV5 {
	st := idempotencyStoreV5{Records: map[string]idempotencyRecordV5{}}
	raw, err := os.ReadFile(idempotencyPathV5())
	if err == nil {
		_ = json.Unmarshal(raw, &st)
	}
	if st.Records == nil {
		st.Records = map[string]idempotencyRecordV5{}
	}
	return st
}

func lookupIdempotentResultV5(c *Command) (*ResultPayload, bool) {
	if c == nil || c.IdempotencyKey == "" || !isMutationActionV5(c.Action) {
		return nil, false
	}
	idempotencyMuV5.Lock()
	defer idempotencyMuV5.Unlock()
	st := loadIdempotencyStoreV5()
	r, ok := st.Records[c.IdempotencyKey]
	if !ok || r.Action != c.Action {
		return nil, false
	}
	var result interface{}
	if len(r.Result) > 0 && string(r.Result) != "null" {
		_ = json.Unmarshal(r.Result, &result)
	}
	return &ResultPayload{
		CommandID: c.ID, LeaseToken: c.LeaseToken, IdempotencyKey: c.IdempotencyKey,
		Success: r.Success, Result: result, Error: r.Error,
	}, true
}

func saveIdempotentResultV5(c *Command, p ResultPayload) {
	if c == nil || c.IdempotencyKey == "" || !isMutationActionV5(c.Action) {
		return
	}
	rawResult, _ := json.Marshal(p.Result)
	idempotencyMuV5.Lock()
	defer idempotencyMuV5.Unlock()
	st := loadIdempotencyStoreV5()
	st.Records[c.IdempotencyKey] = idempotencyRecordV5{
		Action: c.Action, Success: p.Success, Result: rawResult, Error: p.Error, CompletedAt: time.Now().Unix(),
	}
	if len(st.Records) > 128 {
		type kv struct {
			K string
			T int64
		}
		rows := make([]kv, 0, len(st.Records))
		for k, v := range st.Records {
			rows = append(rows, kv{k, v.CompletedAt})
		}
		sort.Slice(rows, func(i, j int) bool { return rows[i].T < rows[j].T })
		for len(st.Records) > 128 {
			delete(st.Records, rows[0].K)
			rows = rows[1:]
		}
	}
	raw, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return
	}
	path := idempotencyPathV5()
	if os.MkdirAll(filepath.Dir(path), 0700) != nil {
		return
	}
	tmp := path + ".tmp"
	if os.WriteFile(tmp, raw, 0600) == nil {
		_ = os.Rename(tmp, path)
	}
}

func verifyEd25519SignatureV5(publicKeyB64 string, payload []byte, signatureB64 string) error {
	pub, err := base64.StdEncoding.DecodeString(publicKeyB64)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		return errors.New("invalid Ed25519 update public key")
	}
	sig, err := base64.StdEncoding.DecodeString(signatureB64)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return errors.New("invalid Ed25519 update signature")
	}
	if !ed25519.Verify(ed25519.PublicKey(pub), payload, sig) {
		return errors.New("update package Ed25519 signature verification failed")
	}
	return nil
}

func effectiveUpdatePublicKeyV5(cfg Config) string {
	if cfg.UpdatePublicKey != "" {
		return cfg.UpdatePublicKey
	}
	return officialUpdatePublicKeyV5
}

func (a *Agent) verifyUpdateSignatureV5(payload []byte, signatureB64 string) error {
	return verifyEd25519SignatureV5(effectiveUpdatePublicKeyV5(a.cfg), payload, signatureB64)
}

// releaseArchV14 is injected into official multiarchitecture bundles with
// -ldflags="-X main.releaseArchV14=<arch>". For GOARCH=arm, runtime.GOARCH
// alone cannot distinguish GOARM 5, 6 and 7; never guess on manual builds.
var releaseArchV14 string

func agentArchV5() string {
	if releaseArchV14 != "" {
		return releaseArchV14
	}
	if runtime.GOARCH == "arm" {
		return "unknown"
	}
	return runtime.GOARCH
}

type nodeHealthV5 struct {
	OK       bool
	RelayMS  float64
	JitterMS float64
	Loss     float64
	TCP      bool
	Score    float64
	Samples  []float64
	Detail   string
}

func computeNodeScoreV5(samples []float64, attempts int, tcpOK bool) (relay, jitter, loss, score float64) {
	if attempts < 1 {
		attempts = 1
	}
	if len(samples) > 0 {
		for _, v := range samples {
			relay += v
		}
		relay /= float64(len(samples))
		for _, v := range samples {
			jitter += math.Abs(v - relay)
		}
		jitter /= float64(len(samples))
	}
	loss = 1 - float64(len(samples))/float64(attempts)
	score = relay + jitter*0.75 + loss*600
	if len(samples) == 0 {
		score += 1500
	}
	if !tcpOK {
		score += 750
	}
	return
}

func scoreNodeV5(conf *UCIConfig, id string) nodeHealthV5 {
	h := nodeHealthV5{}
	if conf == nil || !isNormalProxyNodeV4(conf, id) {
		h.Detail = "not a normal proxy node"
		h.Score = 99999
		return h
	}
	const attempts = 2
	for i := 0; i < attempts; i++ {
		r := testRelay(id)
		if ok, _ := r["ok"].(bool); ok {
			if ms, ok := r["ms"].(float64); ok && ms > 0 {
				h.Samples = append(h.Samples, ms)
			}
		}
	}
	o := conf.Options[id]
	if o["address"] != "" && o["port"] != "" {
		t := testTCP(o["address"], o["port"])
		h.TCP, _ = t["ok"].(bool)
	}
	h.RelayMS, h.JitterMS, h.Loss, h.Score = computeNodeScoreV5(h.Samples, attempts, h.TCP)
	h.OK = len(h.Samples) > 0 && h.Loss < 1
	if !h.OK {
		h.Detail = "relay probes failed"
	}
	return h
}

func relayRankAllV5(conf *UCIConfig, excluded map[string]bool) []relayRank {
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && isNormalProxyNodeV4(conf, id) && !excluded[id] {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	type scored struct {
		r     relayRank
		score float64
	}
	sem := make(chan struct{}, 3)
	out := make(chan scored, len(ids))
	var wg sync.WaitGroup
	for _, id := range ids {
		id := id
		wg.Add(1)
		go func() {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			h := scoreNodeV5(conf, id)
			if !h.OK {
				return
			}
			out <- scored{r: relayRank{ID: id, Name: displayNodeName(id, conf.Options[id]), MS: h.RelayMS}, score: h.Score}
		}()
	}
	wg.Wait()
	close(out)
	rows := []scored{}
	for x := range out {
		rows = append(rows, x)
	}
	sort.Slice(rows, func(i, j int) bool {
		if rows[i].score == rows[j].score {
			return rows[i].r.Name < rows[j].r.Name
		}
		return rows[i].score < rows[j].score
	})
	result := make([]relayRank, 0, len(rows))
	for _, x := range rows {
		result = append(result, x.r)
	}
	return result
}
