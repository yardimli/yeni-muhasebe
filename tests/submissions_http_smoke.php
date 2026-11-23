<?php
declare(strict_types=1);
// Only login, reads, and rejected writes. No real submission is marked read.
$base=$argv[1] ?? 'http://127.0.0.1:18089';
if (!preg_match('~^http://127\.0\.0\.1:\d+$~',$base)) throw new RuntimeException('Local server required.');
$cookie='';
function inbox_request(string $path, ?array $form=null): array {
    global $base,$cookie;
    $headers=$cookie ? ['Cookie: '.$cookie] : [];
    if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>[
        'method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),
        'content'=>$form===null?'':http_build_query($form),'ignore_errors'=>true,'follow_location'=>0,
    ]]));
    foreach($http_response_header as $header)if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$match))$cookie=$match[1];
    preg_match('/\s(\d{3})\s/',$http_response_header[0],$match);
    return [(int)$match[1],(string)$body];
}
function inbox_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
foreach(['forms','list&form=1','download&id=1'] as $action)inbox_check(inbox_request('submissions_api.php?action='.$action)[0]===401,'Anonymous access allowed.');
[$status,$html]=inbox_request('index.php');
preg_match('/name="_token" value="([^"]+)"/',$html,$match);
$env=parse_ini_file(__DIR__.'/../.env',false,INI_SCANNER_RAW);
inbox_check(inbox_request('index.php',['action'=>'login','_token'=>$match[1],'username'=>$env['APP_LOGIN_USERNAME'],'password'=>$env['APP_LOGIN_PASSWORD']])[0]===302,'Login failed.');
[$status,$html]=inbox_request('index.php');
inbox_check($status===200 && str_contains($html,'</html>'),'Desktop rendering failed.');
[$status,$body]=inbox_request('submissions_api.php?action=forms');
$data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
inbox_check($status===200 && count($data['forms'])>0,'No forms returned.');
foreach($data['forms'] as $form){
    inbox_check(str_contains($html,'data-open-submissions="'.$form['id'].'"'),'Form menu missing.');
    inbox_check(str_contains($html,htmlspecialchars($form['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').((int)$form['unread'] > 0 ? ' ('.$form['unread'].')' : '')),'Unread label missing.');
    [$status,$body]=inbox_request('submissions_api.php?action=list&filter=unread&form='.$form['id']);
    $list=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    inbox_check($status===200 && $list['total']==$form['unread'],'Unread listing mismatch.');
}
inbox_check(inbox_request('submissions_api.php?action=open&id=1')[0]===405,'GET modified read state.');
foreach(['open','read','trash','restore','purge'] as $action){
    inbox_check(inbox_request('submissions_api.php?action='.$action,['id'=>1])[0]===419,'Write accepted without CSRF.');
    inbox_check(inbox_request('submissions_api.php?action='.$action)[0]===405,'Write accepted with GET.');
}
inbox_check(inbox_request('submissions_api.php?action=download&id=0')[0]===404,'Missing download accepted.');
preg_match('/name="_token" value="([^"]+)"/',$html,$match);
inbox_request('index.php',['action'=>'logout','_token'=>$match[1]]);
echo "HTTP authentication, five form menus/counts, lists, CSRF, method checks, and missing download passed.\n";
