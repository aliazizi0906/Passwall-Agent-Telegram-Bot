// Hardware-independent LED unit tests; no physical sysfs modifications.
// Bounded temporary LED changes must always restore the original brightness.
package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestIdentifyLEDV14RestoresBrightness(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "blue:status")
	if err := os.MkdirAll(dir, 0755); err != nil {t.Fatal(err)}
	for name, body := range map[string]string{
		"brightness":"23",
		"max_brightness":"255",
		"trigger":"none timer [none] netdev",
	}{
		if err := os.WriteFile(filepath.Join(dir,name),[]byte(body),0600);err != nil{t.Fatal(err)}
	}
	called := false
	err := identifyLEDAtV14(root,"blue:status",func(d time.Duration){
		if d!=1200*time.Millisecond {t.Fatalf("wrong pulse duration: %v",d)}
		called=true
		data,e:=os.ReadFile(filepath.Join(dir,"brightness"));if e!=nil{t.Fatal(e)}
		if string(data)!="0" {t.Fatalf("LED not dimmed during identification: %s",data)}
	})
	if err!=nil {t.Fatal(err)}
	if !called {t.Fatal("pulse never occurred")}
	data,err:=os.ReadFile(filepath.Join(dir,"brightness"))
	if err!=nil||string(data)!="23"{t.Fatalf("LED brightness not restored: %s %v",data,err)}
}

func TestIdentifyLEDV14RejectsUnsafeNamesAndManagedTriggers(t *testing.T) {
	root:=t.TempDir()
	for _,name:=range []string{"../passwd","/foo","..",".","evil;rm",""}{
		if err:=identifyLEDAtV14(root,name,func(time.Duration){});err==nil{
			t.Errorf("invalid name accepted: %q",name)
		}
	}
	dir:=filepath.Join(root,"radio0")
	if err:=os.MkdirAll(dir,0755);err!=nil{t.Fatal(err)}
	for key,value:=range map[string]string{"brightness":"1","max_brightness":"255","trigger":"none [netdev] timer"}{
		if err:=os.WriteFile(filepath.Join(dir,key),[]byte(value),0600);err!=nil{t.Fatal(err)}
	}
	if err:=identifyLEDAtV14(root,"radio0",func(time.Duration){t.Fatal("managed LED touched")});err==nil||!strings.Contains(err.Error(),"active trigger"){
		t.Errorf("should refuse driver-managed LED: %v",err)
	}
}
