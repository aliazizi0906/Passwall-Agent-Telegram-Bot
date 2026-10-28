package main

import "testing"

func TestReleaseArchitectureV14(t *testing.T) {
	before := releaseArchV14
	t.Cleanup(func() { releaseArchV14 = before })
	for _, target := range []string{"armv5", "armv6", "armv7", "arm64", "amd64", "386", "mips", "mipsle", "mips64", "mips64le", "riscv64", "loong64", "ppc64le"} {
		releaseArchV14 = target
		if got := agentArchV5(); got != target {
			t.Fatalf("compiled release target %s resolved to %s", target, got)
		}
	}
}
