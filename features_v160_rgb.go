package main

import (
 "fmt"
 "os"
 "path/filepath"
 "strconv"
 "strings"
)

// Only native Linux sysfs multicolor LEDs with exactly red/green/blue channels
// and a disabled kernel trigger are supported. Vendor-specific LEDs and RGB
// devices without multi_index/multi_intensity remain read-only.
func setRGBLEDV16(params map[string]interface{})(map[string]interface{},error){
 name,e:=stringParam(params,"name");if e!=nil{return nil,e}
 vals:=[]int{}
 for _,color:=range []string{"red","green","blue"}{
  raw,ok:=params[color].(float64)
  if !ok||raw<0||raw>255||raw!=float64(int(raw)){return nil,fmt.Errorf("invalid %s intensity (0-255)",color)}
  vals=append(vals,int(raw))
 }
 previous,e:=setRGBLEDAtV16("/sys/class/leds",name,vals)
 if e!=nil{return nil,e}
 return map[string]interface{}{"led":name,"rgb":vals,"previous_intensities":previous,"changed":true,"hardware":"linux_sysfs_multicolor"},nil
}
func setRGBLEDAtV16(root,name string,rgb []int)([]int,error){
 if len(rgb)!=3||!ledNameV14.MatchString(name)||name=="."||name==".."{return nil,fmt.Errorf("invalid multicolor LED request")}
 for _,v:=range rgb{if v<0||v>255{return nil,fmt.Errorf("invalid RGB component")}}
 base:=filepath.Clean(root);dir:=filepath.Join(base,name)
 if filepath.Dir(dir)!=base{return nil,fmt.Errorf("LED path traversal refused")}
 trigger,e:=os.ReadFile(filepath.Join(dir,"trigger"))
 if e!=nil||!strings.Contains(string(trigger),"[none]"){return nil,fmt.Errorf("RGB LED is controlled by kernel trigger")}
 indices,e:=os.ReadFile(filepath.Join(dir,"multi_index"))
 if e!=nil{return nil,fmt.Errorf("this LED does not provide native multi_index")}
 colors:=strings.Fields(strings.ToLower(string(indices)))
 if len(colors)!=3{return nil,fmt.Errorf("only 3-channel RGB sysfs LEDs supported")}
 values:=make([]int,3)
 seen:=map[string]bool{}
 for i,c:=range colors{
  if seen[c]{return nil,fmt.Errorf("duplicate multicolor LED channel")}
  seen[c]=true
  switch c{case "red":values[i]=rgb[0];case "green":values[i]=rgb[1];case "blue":values[i]=rgb[2];default:return nil,fmt.Errorf("unsupported LED channel %s",c)}
 }
 intensFile:=filepath.Join(dir,"multi_intensity")
 raw,e:=os.ReadFile(intensFile)
 if e!=nil{return nil,fmt.Errorf("RGB intensities unavailable")}
 prior:=[]int{}
 for _,x:=range strings.Fields(string(raw)){
  v,err:=strconv.Atoi(x)
  if err!=nil||v<0||v>255{return nil,fmt.Errorf("unexpected native LED intensity")}
  prior=append(prior,v)
 }
 if len(prior)!=3{return nil,fmt.Errorf("invalid native RGB intensities")}
 maxBytes,e:=os.ReadFile(filepath.Join(dir,"max_brightness"))
 if e!=nil{return nil,e}
 max,e:=strconv.Atoi(strings.TrimSpace(string(maxBytes)))
 if e!=nil||max<1||max>65535{return nil,fmt.Errorf("invalid RGB LED maximum")}
 output:=fmt.Sprintf("%d %d %d",values[0],values[1],values[2])
 if e:=os.WriteFile(intensFile,[]byte(output),0600);e!=nil{return nil,e}
 brightnessFile:=filepath.Join(dir,"brightness")
 before,e:=os.ReadFile(brightnessFile)
 if e!=nil||strings.TrimSpace(string(before))==""{return prior,nil}
 n,e:=strconv.Atoi(strings.TrimSpace(string(before)))
 if e==nil&&n==0 {
  // Kernel multi-intensity cannot illuminate if the master brightness is 0.
  if e=os.WriteFile(brightnessFile,[]byte(strconv.Itoa(max)),0600);e!=nil{
   _=os.WriteFile(intensFile,raw,0600)
   return nil,fmt.Errorf("could not enable native multicolor LED master brightness")
  }
 }
 return prior,nil
}
