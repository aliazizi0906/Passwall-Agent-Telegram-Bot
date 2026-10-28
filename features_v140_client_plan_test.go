package main

// Fixed fixtures; no host firewall or OpenWrt configuration is modified.
import "testing"

func TestClientPolicyPlanV14(t *testing.T) {
 cases:=[]struct{mac,mode string;limit interface{};valid bool}{
 {"02:11:22:33:44:55","block",nil,true},
 {"02-11-22-33-44-55","unblock",nil,true},
 {"02:11:22:33:44:55","limit",float64(1024),true},
 {"ff:ff:ff:ff:ff:ff","block",nil,false},
 {"01:00:5e:00:00:01","block",nil,false},
 {"00:00:00:00:00:00","block",nil,false},
 {"not-a-mac","block",nil,false},
 {"02:11:22:33:44:55","reboot",nil,false},
 {"02:11:22:33:44:55","limit",float64(0),false},
 {"02:11:22:33:44:55","limit",float64(1000001),false},
 {"02:11:22:33:44:55","limit",float64(100.5),false},
 }
 for _,c:=range cases{
   params:=map[string]interface{}{"mac":c.mac,"mode":c.mode}
   if c.limit!=nil {params["limit_kbit"]=c.limit}
   v,err:=clientPolicyPlanV14(params)
   if (err==nil)!=c.valid {t.Errorf("validation for %q %q: expected valid=%v, err=%v",c.mac,c.mode,c.valid,err)}
   if c.valid&&(v["dry_run"]!=true || v["apply_available"]!=false){t.Errorf("unsafe apply advertised: %v",v)}
 }
}
