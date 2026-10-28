package main

import (
	"context"
	"fmt"
	"io"
	"math"
	"net/http"
	"sync"
	"time"
)

// Fixed-provider, quota-bounded download probe. No user-selected URL or SSRF
// surface. This is not a line-rate measurement or a bufferbloat test.
const speedProbeBytesV14 int64 = 2 * 1024 * 1024

var speedProbeMuV14 sync.Mutex
var speedProbeLastV14 time.Time

func speedProbeV14() (map[string]interface{}, error) {
	speedProbeMuV14.Lock()
	defer speedProbeMuV14.Unlock()
	if time.Since(speedProbeLastV14) < 10*time.Minute {
		return nil, fmt.Errorf("bandwidth probe limited to once per 10 minutes per running Agent")
	}
	speedProbeLastV14 = time.Now()
	client := &http.Client{
		Timeout: 25 * time.Second,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			return fmt.Errorf("speed probe redirects are not allowed")
		},
	}
	ctx, cancel := context.WithTimeout(context.Background(), 25*time.Second)
	defer cancel()
	// Endpoint is a fixed provider, not a router/operator-supplied URL.
	endpoint := "https://speed.cloudflare.com/__down?bytes=2097152"
	return measureDownloadV14(ctx, client, endpoint, speedProbeBytesV14)
}

func measureDownloadV14(ctx context.Context, client *http.Client, endpoint string, requested int64) (map[string]interface{}, error) {
	if requested <= 0 || requested > speedProbeBytesV14 {
		return nil, fmt.Errorf("invalid bounded download quota")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Cache-Control", "no-store")
	begin := time.Now()
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("network probe failed: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("network probe returned HTTP %d", resp.StatusCode)
	}
	n, err := io.CopyN(io.Discard, resp.Body, requested+1)
	if n > requested {
		return nil, fmt.Errorf("provider exceeded capped download quota")
	}
	if err != nil && err != io.EOF {
		return nil, fmt.Errorf("network probe download failed: %w", err)
	}
	if n < requested {
		return nil, fmt.Errorf("provider returned %d bytes, below requested %d", n, requested)
	}
	d := time.Since(begin)
	seconds := math.Max(d.Seconds(), 0.001)
	mbps := math.Round((float64(n)*8/seconds/1000000)*100) / 100
	return map[string]interface{}{
		"provider":          "Cloudflare Speed Test (fixed endpoint)",
		"measurement":       "limited download probe, not maximum ISP speed",
		"download_mbps":     mbps,
		"download_bytes":    n,
		"transfer_time_ms":  d.Milliseconds(),
		"data_budget_bytes": requested,
		"privacy":           "The selected provider receives the router public IP address",
		"direction":         "download",
	}, nil
}
