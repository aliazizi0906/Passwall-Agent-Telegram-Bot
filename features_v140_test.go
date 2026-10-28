package main

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestBoundedDownloadProbeV14(t *testing.T) {
	p := bytes.Repeat([]byte("a"), int(speedProbeBytesV14))
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {t.Errorf("expected GET");return}
		w.WriteHeader(200)
		_, _ = w.Write(p)
	}))
	defer s.Close()
	c := &http.Client{Timeout: 3 * time.Second}
	got, err := measureDownloadV14(context.Background(),c,s.URL,speedProbeBytesV14)
	if err != nil {t.Fatalf("bounded probe: %v",err)}
	if got["download_bytes"] != speedProbeBytesV14 {t.Fatalf("unexpected bytes: %v",got["download_bytes"])}
	if got["download_mbps"].(float64) <= 0 {t.Fatalf("bad throughput: %#v",got)}
}

func TestBoundedProbeRejectsOverQuotaAndFailures(t *testing.T) {
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
		if r.URL.Path=="/failed"{http.Error(w,"upstream error",503);return}
		_,_=w.Write(bytes.Repeat([]byte("z"),int(speedProbeBytesV14)+1024))
	}))
	defer s.Close()
	c := &http.Client{Timeout:3*time.Second}
	if _,err:=measureDownloadV14(context.Background(),c,s.URL,speedProbeBytesV14+1);err==nil{t.Fatal("quota too large accepted")}
	if _,err:=measureDownloadV14(context.Background(),c,s.URL+"/failed",speedProbeBytesV14);err==nil{t.Fatal("HTTP failure accepted")}
	if _,err:=measureDownloadV14(context.Background(),c,s.URL,speedProbeBytesV14);err==nil{t.Fatal("over quota accepted")}
}
