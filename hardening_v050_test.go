package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"os"
	"path/filepath"
	"testing"
)

func TestMutationClassificationV5(t *testing.T) {
	if !isMutationActionV5("firewall.redirect.toggle") {
		t.Fatal("expected mutation")
	}
	if isMutationActionV5("system.status") {
		t.Fatal("status must be read-only")
	}
}

func TestIdempotencyJournalV5(t *testing.T) {
	p := filepath.Join(t.TempDir(), "idem.json")
	t.Setenv("PW_AGENT_IDEMPOTENCY_PATH", p)
	c := &Command{ID: 7, Action: "wifi.set_ssid", IdempotencyKey: "abc", LeaseToken: "lease2"}
	saveIdempotentResultV5(c, ResultPayload{Success: true, Result: map[string]interface{}{"ok": true}})
	got, ok := lookupIdempotentResultV5(c)
	if !ok || !got.Success || got.LeaseToken != "lease2" {
		t.Fatalf("unexpected replay: %#v %v", got, ok)
	}
	if _, err := os.Stat(p); err != nil {
		t.Fatal(err)
	}
}

func TestEd25519VerificationV5(t *testing.T) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	msg := []byte("release")
	sig := ed25519.Sign(priv, msg)
	if err := verifyEd25519SignatureV5(base64.StdEncoding.EncodeToString(pub), msg, base64.StdEncoding.EncodeToString(sig)); err != nil {
		t.Fatal(err)
	}
	if err := verifyEd25519SignatureV5(base64.StdEncoding.EncodeToString(pub), []byte("tampered"), base64.StdEncoding.EncodeToString(sig)); err == nil {
		t.Fatal("tampered payload accepted")
	}
}

func TestHealthScoreV5(t *testing.T) {
	_, _, _, good := computeNodeScoreV5([]float64{70, 80}, 2, true)
	_, _, _, bad := computeNodeScoreV5([]float64{70}, 2, false)
	if good >= bad {
		t.Fatalf("good=%v bad=%v", good, bad)
	}
}
