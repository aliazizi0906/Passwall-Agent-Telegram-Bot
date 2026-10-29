package main
import ("testing";"os";"path/filepath")
func TestNativeRGBV16(t *testing.T){
 root:=t.TempDir()
 dir:=filepath.Join(root,"rgb:status")
 if e:=os.MkdirAll(dir,0700);e!=nil{t.Fatal(e)}
 write:=func(p,v string){if e:=os.WriteFile(filepath.Join(dir,p),[]byte(v),0600);e!=nil{t.Fatal(e)}}
 write("trigger","none [none] timer")
 write("multi_index","blue red green")
 write("multi_intensity","5 6 7")
 write("brightness","20")
 write("max_brightness","255")
 old,e:=setRGBLEDAtV16(root,"rgb:status",[]int{20,30,40})
 if e!=nil||len(old)!=3||old[0]!=5{t.Fatalf("unexpected old RGB: %v %v",old,e)}
 raw,_:=os.ReadFile(filepath.Join(dir,"multi_intensity"))
 if string(raw)!="40 20 30"{t.Fatalf("RGB channel order incorrect %q",raw)}
 write("trigger","none [timer]")
 if _,e=setRGBLEDAtV16(root,"rgb:status",[]int{2,3,4});e==nil{t.Fatal("allowed RGB write under timer trigger")}
 write("trigger","[none]")
 if _,e=setRGBLEDAtV16(root,"../invalid",[]int{2,3,4});e==nil{t.Fatal("path traversal permitted")}
 if _,e=setRGBLEDAtV16(root,"rgb:status",[]int{2,300,4});e==nil{t.Fatal("invalid intensity accepted")}
 write("multi_index","blue green amber")
 if _,e=setRGBLEDAtV16(root,"rgb:status",[]int{2,3,4});e==nil{t.Fatal("unverified hardware channel accepted")}
}
