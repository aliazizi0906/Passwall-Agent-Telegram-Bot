package main

import "testing"

func TestOpenVPNRoutePlanV15(t *testing.T) {
    good:=map[string]interface{}{"profile":"client_a","mode":"through_vpn","cidr":"192.0.2.0/24"}
    got,err:=openvpnRoutePlanV15(good)
    if err!=nil||got["applied"]!=false||got["status"]!="preview_only" {t.Fatalf("invalid preview: %v %v",got,err)}
    for _,bad:=range []struct{key,value string}{
        {"cidr","0.0.0.0/0"},{"cidr","::/0"},{"cidr","192.0.2.1/24"},
        {"cidr","127.0.0.0/8"},{"cidr","not-cidr"},{"profile","../etc"},
        {"mode","run_command"},
    } {
        p:=map[string]interface{}{"profile":good["profile"],"mode":good["mode"],"cidr":good["cidr"]}
        p[bad.key]=bad.value
        if _,err:=openvpnRoutePlanV15(p);err==nil{t.Errorf("accepted %s=%s",bad.key,bad.value)}
    }
}
func TestOpenVPNServiceActionValidationV15(t *testing.T) {
    if _,err:=openvpnServiceControlV15(map[string]interface{}{"mode":"enable; reboot"});err==nil {t.Fatal("accepted command injection") }
}
