package main

import (
    "fmt"
    "net"
    "os"
    "regexp"
    "sort"
    "strings"
    "time"
)

var openvpnSectionV15 = regexp.MustCompile("^[A-Za-z0-9_-]{1,64}$")

// The profile inventory deliberately never returns config content, credentials,
// inline keys, remote endpoints or private certificates.
func openvpnStatusV15() (map[string]interface{}, error) {
    result := map[string]interface{}{"installed":false,"profiles":[]map[string]interface{}{},"available_actions":[]string{}}
    if _,err:=os.Stat("/etc/init.d/openvpn");err!=nil {result["reason"]="OpenVPN init script is not installed";return result,nil}
    result["installed"]=true
    result["service"]=serviceState("openvpn")
    result["available_actions"]=[]string{"start","stop","restart"}
    cfg,err:=loadUCIConfig("openvpn")
    if err!=nil {result["reason"]="OpenVPN UCI profiles unavailable";return result,nil}
    names:=[]string{}
    for name,kind:=range cfg.SectionType {if kind=="openvpn" && openvpnSectionV15.MatchString(name){names=append(names,name)}}
    sort.Strings(names)
    rows:=[]map[string]interface{}{}
    for _,name:=range names {if len(rows)>=32{break};o:=cfg.Options[name]; rows=append(rows,map[string]interface{}{"name":name,"enabled":o["enabled"]=="1","configured":o["config"]!=""||o["dev"]!=""||o["client"]!=""})}
    result["profiles"]=rows
    result["warning"]="Service controls affect all enabled OpenVPN profiles. Network route/firewall changes are not applied by this panel."
    return result,nil
}

func openvpnServiceControlV15(params map[string]interface{}) (map[string]interface{},error) {
    mode,err:=stringParam(params,"mode")
    if err!=nil{return nil,err}
    if mode!="start" && mode!="stop" && mode!="restart"{return nil,fmt.Errorf("invalid OpenVPN service action")}
    if _,err:=os.Stat("/etc/init.d/openvpn");err!=nil{return nil,fmt.Errorf("OpenVPN service not installed")}
    _,err=runOutput(40*time.Second,"/etc/init.d/openvpn",mode)
    if err!=nil{return nil,fmt.Errorf("OpenVPN service operation failed: %w",err)}
    return map[string]interface{}{"operation":mode,"service":serviceState("openvpn"),"warning":"Connectivity and routes may change; verify access from the router console."},nil
}

// Preview an explicit policy request before any potentially disruptive route
// or firewall write. This operation NEVER installs packet filters or routes.
func openvpnRoutePlanV15(params map[string]interface{}) (map[string]interface{},error) {
    profile,err:=stringParam(params,"profile")
    if err!=nil||!openvpnSectionV15.MatchString(profile){return nil,fmt.Errorf("invalid profile name")}
    kind,err:=stringParam(params,"mode")
    if err!=nil|| (kind!="through_vpn"&&kind!="bypass_vpn"){return nil,fmt.Errorf("invalid route policy mode")}
    cidr,err:=stringParam(params,"cidr")
    if err!=nil{return nil,err}
    ip,network,err:=net.ParseCIDR(cidr)
    if err!=nil||ip==nil||network==nil||len(cidr)>64{return nil,fmt.Errorf("invalid destination CIDR")}
    if ip.IsMulticast()||ip.IsUnspecified()||ip.IsLoopback(){return nil,fmt.Errorf("unsafe special-use destination")}
    if net.IP.Equal(ip,network.IP)==false{return nil,fmt.Errorf("CIDR must specify a network address, not a host address")}
    if strings.HasPrefix(cidr,"0.0.0.0/0")||cidr=="::/0"{return nil,fmt.Errorf("default route changes require a recovery watchdog")}
    return map[string]interface{}{"profile":profile,"destination_cidr":network.String(),"mode":kind,"applied":false,"status":"preview_only","warning":"No route, firewall, NAT or VPN profile changes were made; safe-apply/rollback is required before activation."},nil
}
