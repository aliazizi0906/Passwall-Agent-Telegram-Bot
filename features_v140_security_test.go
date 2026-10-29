// Resolver tests use synthetic data and never query external DNS.
package main

import "testing"

func TestParseResolvV14RedactsAndCounts(t *testing.T) {
	n, local := parseResolvV14("nameserver 127.0.0.1\nnameserver ::1\n")
	if n != 2 || !local {
		t.Fatalf("expected two local DNS proxies, got %d %v", n, local)
	}
	n, local = parseResolvV14("# ignore\nnameserver 1.1.1.1\nnameserver bad-ip\nsearch example.com\n")
	if n != 1 || local {
		t.Fatalf("public resolver presence not detected: %d %v", n, local)
	}
	n, local = parseResolvV14("nameserver \nnot-a-nameserver 1.1.1.1\n")
	if n != 0 || local {
		t.Fatalf("should reject invalid resolv.conf entries")
	}
}
