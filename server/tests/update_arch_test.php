<?php
declare(strict_types=1);
require dirname(__DIR__).'/hardening_v050.php';
$cases=[
 'armv5tel'=>'armv5','armv5'=>'armv5','armv6l'=>'armv6',
 'armv7l'=>'armv7','armv8l'=>'armv7','aarch64'=>'arm64','x86_64'=>'amd64',
 'i386'=>'386','mips'=>'mips','mipsel'=>'mipsle','mips64'=>'mips64',
 'mips64el'=>'mips64le','riscv64'=>'riscv64','loongarch64'=>'loong64',
 'ppc64le'=>'ppc64le'
];
foreach($cases as $input=>$expected){
 $actual=v050_normalize_arch($input);
 if($actual!==$expected)throw new RuntimeException('Unexpected update architecture '.$input.': '.$actual);
}
foreach(['../../etc','arm32','unknown','armv7;reboot'] as $bad){
 try{v050_normalize_arch($bad);throw new RuntimeException('Unsafe architecture accepted: '.$bad);}
 catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'Unsafe architecture accepted'))throw $e;}
}
echo "Update architecture normalization: all supported CPU families and unsafe values OK\n";
