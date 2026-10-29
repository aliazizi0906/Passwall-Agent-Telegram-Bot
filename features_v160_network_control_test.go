package main

import (
  "testing"
)

func TestPolicyCIDRV16(t *testing.T) {
 for _,cidr:=range []string{"192.0.2.0/24","2001:db8::/64"} {
  if _,err:=validPolicyCIDRV16(cidr);err!=nil{t.Errorf("valid CIDR rejected %s: %v",cidr,err)}
 }
 for _,cidr:=range []string{"0.0.0.0/0","::/0","192.0.2.2/24","bad","127.0.0.0/8","224.0.0.0/4","2001:db8::2/64"}{
  if _,err:=validPolicyCIDRV16(cidr);err==nil{t.Errorf("unsafe CIDR allowed: %s",cidr)}
 }
}
func TestPBRPolicyInputV16(t *testing.T) {
 if _,err:=pbrPolicyAddV16(map[string]interface{}{"name":"../evil","cidr":"192.0.2.0/24","interface":"wan"});err==nil{t.Fatal("accepted path traversal in PBR name")}
 if _,err:=pbrPolicyAddV16(map[string]interface{}{"name":"pw_test","cidr":"0.0.0.0/0","interface":"wan"});err==nil{t.Fatal("accepted default route")}
 if _,err:=pbrPolicyDeleteV16(map[string]interface{}{"name":"wan"});err==nil{t.Fatal("allowed unmanaged PBR policy delete")}
}
func TestFirewallAndSQMSafetyV16(t *testing.T) {
 if _,err:=clientInternetBlockV16(map[string]interface{}{"mac":"01:00:5e:00:00:fb","blocked":true});err==nil{t.Fatal("accepted multicast MAC")}
 if _,err:=clientInternetBlockV16(map[string]interface{}{"mac":"00:00:00:00:00:00","blocked":true});err==nil{t.Fatal("accepted zero MAC")}
 if _,err:=vpnWANGuardV16(map[string]interface{}{"enabled":"yes"});err==nil{t.Fatal("accepted nonboolean guard")}
 if _,err:=wifiChannelSafeV16(map[string]interface{}{"radio":"radio0","channel":"1;reboot"});err==nil{t.Fatal("accepted command injection")}
 if _,err:=sqmQueueSetV16(map[string]interface{}{"section":"../../","download_kbit":"1000","upload_kbit":"1000"});err==nil{t.Fatal("accepted section traversal")}
 if _,err:=openvpnProfileToggleV16(map[string]interface{}{"profile":"client;reboot","enabled":true});err==nil{t.Fatal("accepted OpenVPN shell injection")}
}
func TestConfigTransactionV16Guards(t *testing.T) {
 for _,p:=range []string{"passwd","/etc/shadow","network;reboot","openvpn_bak"} {
  if configPackageSupportedV16(p){t.Errorf("unsafe UCI package permitted %s",p)}
 }
 for _,p:=range []string{"pbr","firewall","sqm","wireless","openvpn"} {
  if !configPackageSupportedV16(p){t.Errorf("supported UCI package denied %s",p)}
 }
 if _,err:=configSafeConfirmV16(map[string]interface{}{"transaction_id":"../bad"});err==nil{t.Fatal("bad confirmation accepted")}
}
