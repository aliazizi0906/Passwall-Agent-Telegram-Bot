<?php
declare(strict_types=1);
require dirname(__DIR__).'/v130_command_validation.php';

function expectOkay(string $name, string $action,array $data,bool $confirmed,array $expected): void {
    $got=dash_v130_safe_params($action,$data,$confirmed);
    if($got!==$expected)throw new RuntimeException($name.': unexpected data');
    echo "PASS ".$name.PHP_EOL;
}
function expectFailure(string $name,string $action,array $data,bool $confirmed): void {
    try{dash_v130_safe_params($action,$data,$confirmed);}
    catch(RuntimeException $e){echo "PASS ".$name.PHP_EOL;return;}
    throw new RuntimeException($name.': expected validation error');
}

expectOkay('read-only untouched','passwall2.nodes.list',[],false,[]);
expectOkay('safe node id','passwall2.node.test',['id'=>'node-01'],false,['id'=>'node-01']);
expectOkay('safe select','passwall2.node.select',['id'=>'node_02'],true,['id'=>'node_02']);
expectFailure('select must confirm','passwall2.node.select',['id'=>'node-01'],false);
expectFailure('delete must confirm','passwall2.node.delete',['id'=>'node-01'],false);
expectFailure('no shell chars in ids','passwall2.node.test',['id'=>'x;cat /etc/shadow'],false);
expectFailure('no delete all','passwall2.subscription.delete',['id'=>'all'],true);
expectOkay('valid encrypted link','passwall2.node.add_link',['link'=>'vless://masked@example.org:443'],true,['link'=>'vless://masked@example.org:443']);
expectFailure('unsupported link','passwall2.node.add_link',['link'=>'file:///etc/shadow'],true);
expectFailure('link must confirm','passwall2.node.add_link',['link'=>'vless://x@y'],false);
expectOkay('https subscription','passwall2.subscription.add',['url'=>'https://example.com/sub','remark'=>'test'],true,['url'=>'https://example.com/sub','remark'=>'test']);
expectFailure('reject http','passwall2.subscription.add',['url'=>'http://example.com/sub','remark'=>'test'],true);
expectFailure('reject credentials in URL','passwall2.subscription.add',['url'=>'https://user:password@example.com/sub','remark'=>'test'],true);
expectFailure('missing remark','passwall2.subscription.add',['url'=>'https://example.com/sub','remark'=>''],true);
echo "All v1.3 parameter validation checks passed.".PHP_EOL;
