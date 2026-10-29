<?php
declare(strict_types=1);
session_start();
$config = require __DIR__ . '/back/config/config.php';

function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_check(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Sesión expirada. Recarga la página.'); } }
function db(): PDO {
    static $pdo = null; global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $pdo = new PDO(
        "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}",
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $pdo;
}
function logged(): bool { return !empty($_SESSION['uid']); }
function redirect(string $url): never { header('Location: '.$url); exit; }
function page_url(string $p='dashboard', array $q=[]): string { global $config; return rtrim($config['base_url'],'/').'/?'.http_build_query(array_merge(['page'=>$p],$q)); }
function login_attempt(string $correo, string $password): bool {
    $st=db()->prepare('SELECT * FROM usuarios_sistema WHERE correo=? AND activo=1 LIMIT 1');
    $st->execute([$correo]); $u=$st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) return false;
    $_SESSION['uid']=$u['id_usuario'];
    $_SESSION['nombre']=trim(($u['nombre'] ?? '').' '.($u['apellidos'] ?? '')) ?: ($u['nombre'] ?? 'Usuario');
    $_SESSION['rol']=$u['rol'];
    session_regenerate_id(true);
    return true;
}
function current_user(PDO $pdo): array {
    $st=$pdo->prepare('SELECT * FROM usuarios_sistema WHERE id_usuario=? LIMIT 1');
    $st->execute([(int)($_SESSION['uid']??0)]);
    return $st->fetch() ?: [];
}
function fmt_phone(string $p): string { return preg_replace('/\D+/', '', $p) ?? ''; }
function status_label(string $s): string {
    return [
        'nueva'=>'Nueva','en_revision'=>'En revisión','contactado'=>'Contactado','aprobado'=>'Aprobado',
        'no_aprobado'=>'No aprobado','cerrada'=>'Cerrada'
    ][$s] ?? ucfirst(str_replace('_',' ',$s));
}
function journey_label(string $s): string {
    return [
        'prospecto'=>'Prospecto','prospecto_recurrente'=>'Prospecto recurrente',
        'cliente'=>'Cliente','cliente_recurrente'=>'Cliente recurrente'
    ][$s] ?? ucfirst(str_replace('_',' ',$s));
}
function role_label(string $r): string {
    return [
        'superadministrador'=>'Superadministrador','administrador'=>'Administrador',
        'asesor'=>'Usuario','consulta'=>'Solo consulta'
    ][$r] ?? ucfirst($r);
}
function modules_catalog(): array {
    return [
        'dashboard'=>['Dashboard','Ver indicadores, Customer Journey, actividad reciente y resumen general.'],
        'solicitudes'=>['Registros Aidores','Consultar registros y, con Editar, cambiar su estado.'],
        'asesor'=>['Solicitudes de asesor','Consultar solicitudes y, con Editar, actualizar su seguimiento.'],
        'contactos'=>['Contactos','Consultar contactos y, con Editar, eliminarlos.'],
        'conversaciones'=>['Conversaciones','Consultar conversaciones registradas por el bot.'],
        'mensajes'=>['Mensajes','Consultar destinatarios y, con Editar, realizar envíos por WhatsApp.'],
        'configuracion'=>['Configuración','Consultar la configuración técnica del CRM.'],
        'usuarios'=>['Usuarios','Administrar cuentas, roles y permisos del CRM.'],
    ];
}
function user_permissions(PDO $pdo, int $uid): array {
    static $cache=[];
    if (isset($cache[$uid])) return $cache[$uid];
    $st=$pdo->prepare('SELECT modulo,puede_ver,puede_editar FROM usuario_permisos WHERE id_usuario=?');
    $st->execute([$uid]);
    $out=[];
    foreach($st->fetchAll() as $r) $out[$r['modulo']]=['ver'=>(bool)$r['puede_ver'],'editar'=>(bool)$r['puede_editar']];
    return $cache[$uid]=$out;
}
function is_superadmin(): bool { return ($_SESSION['rol']??'')==='superadministrador'; }
function can_module(PDO $pdo, string $module, string $mode='ver'): bool {
    if (is_superadmin()) return true;
    $p=user_permissions($pdo,(int)($_SESSION['uid']??0));
    if ($mode==='editar') return !empty($p[$module]['editar']);
    return !empty($p[$module]['ver']) || !empty($p[$module]['editar']);
}
function require_edit(PDO $pdo, string $module): void {
    if (!can_module($pdo,$module,'editar')) throw new RuntimeException('No tienes permiso para editar este módulo.');
}
function route_module(string $page): ?string {
    return [
        'dashboard'=>'dashboard','solicitudes'=>'solicitudes','solicitud'=>'solicitudes','asesor'=>'asesor',
        'contactos'=>'contactos','conversaciones'=>'conversaciones','mensajes'=>'mensajes',
        'configuracion'=>'configuracion','usuarios'=>'usuarios'
    ][$page] ?? null;
}
function nav_link(PDO $pdo,string $p,string $label,string $current,string $module): string {
    if (!can_module($pdo,$module,'ver')) return '';
    return '<a class="'.($p===$current?'active':'').'" href="'.e(page_url($p)).'">'.e($label).'</a>';
}
function send_template(string $to, string $message): array {
    global $config;
    $url=trim((string)$config['firebase_crm_url']); $key=(string)$config['firebase_admin_key'];
    if ($url==='' || $key==='') throw new RuntimeException('Falta configurar Firebase CRM URL o WEB_ADMIN_KEY.');
    $payload=json_encode(['to'=>fmt_phone($to),'message'=>$message], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-admin-key: '.$key],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>25]);
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if ($raw===false || $err) throw new RuntimeException('Error de conexión con Firebase: '.$err);
    $data=json_decode($raw,true);
    if ($code<200 || $code>=300) throw new RuntimeException($data['error'] ?? ('Firebase respondió HTTP '.$code));
    return is_array($data)?$data:[];
}
function sync_customer_journey(PDO $pdo): void {
    $pdo->exec("UPDATE contactos c SET tipo_contacto = CASE
        WHEN (SELECT COUNT(*) FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto AND s.estado='aprobado') >= 4 THEN 'cliente_recurrente'
        WHEN (SELECT COUNT(*) FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto AND s.estado='aprobado') >= 1 THEN 'cliente'
        WHEN (SELECT COUNT(*) FROM conversaciones cv WHERE cv.id_contacto=c.id_contacto) >= 3 THEN 'prospecto_recurrente'
        ELSE 'prospecto' END");
}
function initials(array $u): string {
    $a=trim((string)($u['nombre']??'')); $b=trim((string)($u['apellidos']??''));
    $x=$a!==''?mb_substr($a,0,1):'U'; $y=$b!==''?mb_substr($b,0,1):'';
    return mb_strtoupper($x.$y);
}
function save_permissions(PDO $pdo, int $uid, array $view, array $edit): void {
    $mods=array_keys(modules_catalog());
    $pdo->prepare('DELETE FROM usuario_permisos WHERE id_usuario=?')->execute([$uid]);
    $st=$pdo->prepare('INSERT INTO usuario_permisos(id_usuario,modulo,puede_ver,puede_editar) VALUES(?,?,?,?)');
    foreach($mods as $m){
        $e=!empty($edit[$m])?1:0; $v=(!empty($view[$m])||$e)?1:0;
        if($v||$e) $st->execute([$uid,$m,$v,$e]);
    }
}

if (isset($_GET['logout'])) { session_destroy(); redirect(rtrim($config['base_url'],'/').'/'); }
$loginError='';
if (!logged() && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login'])) {
    csrf_check();
    if (!login_attempt(trim($_POST['correo']??''), $_POST['password']??'')) $loginError='Correo o contraseña incorrectos.';
    else redirect(page_url());
}
if (!logged()) {
    $token=csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#5C87C8"><title>Aidora | CRM</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head><body class="login-body"><main class="login-shell"><section class="brand-panel"><div class="shape shape-one"></div><div class="shape shape-two"></div><div class="shape shape-three"></div><div class="brand-content"><div class="logo-card"><img src="assets/aidora-logo.png" alt="Aidora"></div><p class="eyebrow">AIDORA · CRM</p><h1>Personas que cuidan,<br>información que acompaña.</h1><p class="lead">Centraliza registros de Aidores, disponibilidad, experiencia, conversaciones y seguimiento desde una sola plataforma.</p><div class="features"><article class="feature-card"><span class="feature-value">360°</span><span class="feature-label">perfil del Aidor</span></article><article class="feature-card"><span class="feature-value">CRM</span><span class="feature-label">seguimiento de registros</span></article><article class="feature-card"><span class="feature-value">BOT</span><span class="feature-label">integración WhatsApp</span></article></div></div></section><section class="access-panel"><div class="access-wrap"><p class="eyebrow access-eyebrow">ACCESO ADMINISTRATIVO</p><h2>Bienvenido</h2><p class="access-copy">Ingresa con tu cuenta para administrar los registros y solicitudes de Aidora.</p><?php if($loginError): ?><div class="flash error"><?=e($loginError)?></div><?php endif; ?><form class="login-form" method="post"><input type="hidden" name="csrf" value="<?=e($token)?>"><input type="hidden" name="login" value="1"><div class="field"><label>Correo electrónico</label><input type="email" name="correo" placeholder="correo@aidora.com" autocomplete="username" required></div><div class="field"><label>Contraseña</label><div class="password-wrap"><input type="password" id="password" name="password" placeholder="Ingresa tu contraseña" autocomplete="current-password" required><button class="toggle-password" type="button">Ver</button></div></div><button class="submit-btn" type="submit"><span>Ingresar</span><span>→</span></button></form><p class="footer-note">Aidora · Gestión y seguimiento centralizado</p></div></section></main><script>const t=document.querySelector('.toggle-password'),p=document.getElementById('password');t?.addEventListener('click',()=>{const v=p.type==='text';p.type=v?'password':'text';t.textContent=v?'Ver':'Ocultar';});</script></body></html><?php exit; }

$page=$_GET['page']??'dashboard';
$pdo=db();
$currentUser=current_user($pdo);
if (!$currentUser || empty($currentUser['activo'])) { session_destroy(); redirect(rtrim($config['base_url'],'/').'/'); }
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check(); $action=$_POST['action']??'';
    try {
        if ($action==='delete_contact') {
            require_edit($pdo,'contactos');
            $id=(int)($_POST['id']??0); if(!$id) throw new RuntimeException('Contacto inválido.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM solicitudes_aidores WHERE id_contacto=?')->execute([$id]);
            $pdo->prepare('DELETE FROM solicitudes_asesor WHERE id_contacto=?')->execute([$id]);
            $st=$pdo->prepare('SELECT whatsapp FROM contactos WHERE id_contacto=?'); $st->execute([$id]); $wa=(string)($st->fetchColumn()?:'');
            if($wa!=='') $pdo->prepare('DELETE FROM bot_sesiones WHERE whatsapp=?')->execute([$wa]);
            $pdo->prepare('DELETE FROM contactos WHERE id_contacto=?')->execute([$id]);
            $pdo->commit(); $_SESSION['flash']=['ok','Contacto eliminado.']; redirect(page_url('contactos'));
        }
        if ($action==='update_application_status') {
            require_edit($pdo,'solicitudes');
            $id=(int)($_POST['id']??0); $status=(string)($_POST['estado']??'');
            $allowed=['nueva','en_revision','contactado','aprobado','no_aprobado'];
            if(!in_array($status,$allowed,true)) throw new RuntimeException('Estado inválido.');
            $st=$pdo->prepare('SELECT id_contacto FROM solicitudes_aidores WHERE id_solicitud=?'); $st->execute([$id]); $cid=(int)($st->fetchColumn()?:0);
            $pdo->prepare('UPDATE solicitudes_aidores SET estado=? WHERE id_solicitud=?')->execute([$status,$id]);
            sync_customer_journey($pdo);
            $_SESSION['flash']=['ok','Estado actualizado.']; redirect(page_url('solicitud',['id'=>$id]));
        }
        if ($action==='update_advisor_status') {
            require_edit($pdo,'asesor');
            $id=(int)($_POST['id']??0); $status=(string)($_POST['estado']??'');
            $allowed=['nueva','contactado','cerrada']; if(!in_array($status,$allowed,true)) throw new RuntimeException('Estado inválido.');
            $pdo->prepare('UPDATE solicitudes_asesor SET estado=? WHERE id_solicitud=?')->execute([$status,$id]);
            $_SESSION['flash']=['ok','Solicitud actualizada.']; redirect(page_url('asesor'));
        }
        if ($action==='send_message') {
            require_edit($pdo,'mensajes');
            sync_customer_journey($pdo);
            $message=trim($_POST['mensaje']??''); $segment=$_POST['segmento']??'individual'; $contactId=(int)($_POST['contacto']??0);
            if ($message==='') throw new RuntimeException('Escribe el mensaje.');
            $sql='SELECT DISTINCT c.id_contacto,c.whatsapp,c.nombre_completo FROM contactos c'; $where=' WHERE c.activo=1'; $args=[];
            if($segment==='individual'){ if(!$contactId) throw new RuntimeException('Selecciona un contacto.'); $where.=' AND c.id_contacto=?'; $args[]=$contactId; }
            elseif(in_array($segment,['prospecto','prospecto_recurrente','cliente','cliente_recurrente'],true)){ $where.=' AND c.tipo_contacto=?'; $args[]=$segment; }
            elseif($segment==='registrados'){ $where.=' AND EXISTS(SELECT 1 FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto)'; }
            elseif(in_array($segment,['nueva','en_revision','aprobado','no_aprobado'],true)){ $where.=' AND EXISTS(SELECT 1 FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto AND s.estado=?)'; $args[]=$segment; }
            elseif($segment==='asesor'){ $where.=' AND EXISTS(SELECT 1 FROM solicitudes_asesor a WHERE a.id_contacto=c.id_contacto)'; }
            elseif($segment!=='todos'){ throw new RuntimeException('Segmento inválido.'); }
            $st=$pdo->prepare($sql.$where.' ORDER BY c.id_contacto DESC'); $st->execute($args); $targets=$st->fetchAll();
            if(!$targets) throw new RuntimeException('No hay contactos para ese segmento.');
            $pdo->prepare('INSERT INTO mensajes_campanas(nombre,mensaje,segmento,total_destinatarios,creado_por) VALUES(?,?,?,?,?)')->execute(['Envío CRM',$message,$segment,count($targets),$_SESSION['uid']]);
            $camp=(int)$pdo->lastInsertId(); $ok=0;$fail=0;
            foreach($targets as $c){
                $pdo->prepare("INSERT INTO mensajes_envios(id_campana,id_contacto,whatsapp,mensaje,estado,tipo_envio,template_nombre,intentos) VALUES(?,?,?,?, 'pendiente','template',?,1)")->execute([$camp,$c['id_contacto'],$c['whatsapp'],$message,$config['template_name']]);
                $eid=(int)$pdo->lastInsertId();
                try {
                    $r=send_template($c['whatsapp'],$message); $mid=$r['message_id']??($r['messages'][0]['id']??null);
                    $pdo->prepare("UPDATE mensajes_envios SET estado='aceptado_meta',meta_message_id=?,procesado_en=NOW() WHERE id_envio=?")->execute([$mid,$eid]); $ok++;
                } catch(Throwable $ex){
                    $pdo->prepare("UPDATE mensajes_envios SET estado='error',detalle_error=?,procesado_en=NOW() WHERE id_envio=?")->execute([$ex->getMessage(),$eid]); $fail++;
                }
            }
            $_SESSION['flash']=['ok',"Envío procesado: $ok aceptados, $fail con error."]; redirect(page_url('mensajes'));
        }
        if ($action==='profile_update') {
            $name=trim((string)($_POST['nombre']??'')); $last=trim((string)($_POST['apellidos']??'')); $email=trim((string)($_POST['correo']??''));
            if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Captura un nombre y correo válidos.');
            $st=$pdo->prepare('SELECT COUNT(*) FROM usuarios_sistema WHERE correo=? AND id_usuario<>?'); $st->execute([$email,$_SESSION['uid']]);
            if((int)$st->fetchColumn()>0) throw new RuntimeException('Ese correo ya está registrado.');
            $pdo->prepare('UPDATE usuarios_sistema SET nombre=?,apellidos=?,correo=? WHERE id_usuario=?')->execute([$name,$last,$email,$_SESSION['uid']]);
            $_SESSION['nombre']=trim($name.' '.$last); $_SESSION['flash']=['ok','Datos personales actualizados.']; redirect(page_url('perfil'));
        }
        if ($action==='profile_password') {
            $current=(string)($_POST['actual']??''); $new=(string)($_POST['nueva']??''); $confirm=(string)($_POST['confirmar']??'');
            $u=current_user($pdo);
            if(!password_verify($current,$u['password_hash']??'')) throw new RuntimeException('La contraseña actual no es correcta.');
            if(strlen($new)<8) throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
            if($new!==$confirm) throw new RuntimeException('La confirmación de contraseña no coincide.');
            $pdo->prepare('UPDATE usuarios_sistema SET password_hash=? WHERE id_usuario=?')->execute([password_hash($new,PASSWORD_DEFAULT),$_SESSION['uid']]);
            $_SESSION['flash']=['ok','Contraseña actualizada.']; redirect(page_url('perfil'));
        }
        if ($action==='profile_photo') {
            if(empty($_FILES['foto']) || ($_FILES['foto']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecciona una imagen válida.');
            if((int)$_FILES['foto']['size']>3*1024*1024) throw new RuntimeException('La imagen no debe superar 3 MB.');
            $tmp=(string)$_FILES['foto']['tmp_name'];
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
            if(!$ext) throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WEBP.');
            $dir=__DIR__.'/uploads/profiles'; if(!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('No se pudo crear la carpeta de perfiles.');
            $name='u'.(int)$_SESSION['uid'].'_'.bin2hex(random_bytes(6)).'.'.$ext; $dest=$dir.'/'.$name;
            if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('No se pudo guardar la fotografía.');
            $old=(string)($currentUser['foto_perfil']??'');
            $pdo->prepare('UPDATE usuarios_sistema SET foto_perfil=? WHERE id_usuario=?')->execute(['uploads/profiles/'.$name,$_SESSION['uid']]);
            if($old!=='' && str_starts_with($old,'uploads/profiles/') && is_file(__DIR__.'/'.$old)) @unlink(__DIR__.'/'.$old);
            $_SESSION['flash']=['ok','Fotografía actualizada.']; redirect(page_url('perfil'));
        }
        if ($action==='create_user') {
            require_edit($pdo,'usuarios');
            $name=trim((string)($_POST['nombre']??'')); $last=trim((string)($_POST['apellidos']??'')); $email=trim((string)($_POST['correo']??'')); $pass=(string)($_POST['password']??'');
            $role=(string)($_POST['rol']??'asesor'); $active=!empty($_POST['activo'])?1:0;
            $allowed=is_superadmin()?['superadministrador','administrador','asesor','consulta']:['administrador','asesor','consulta'];
            if(!in_array($role,$allowed,true)) throw new RuntimeException('Rol inválido.');
            if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Captura nombre y correo válidos.');
            if(strlen($pass)<8) throw new RuntimeException('La contraseña temporal debe tener al menos 8 caracteres.');
            $st=$pdo->prepare('SELECT COUNT(*) FROM usuarios_sistema WHERE correo=?'); $st->execute([$email]); if((int)$st->fetchColumn()>0) throw new RuntimeException('Ese correo ya está registrado.');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO usuarios_sistema(nombre,apellidos,correo,password_hash,rol,activo) VALUES(?,?,?,?,?,?)')->execute([$name,$last,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$active]);
            $uid=(int)$pdo->lastInsertId(); save_permissions($pdo,$uid,$_POST['ver']??[],$_POST['editar']??[]); $pdo->commit();
            $_SESSION['flash']=['ok','Usuario creado correctamente.']; redirect(page_url('usuarios'));
        }
        if ($action==='update_user') {
            require_edit($pdo,'usuarios');
            $uid=(int)($_POST['id_usuario']??0); if(!$uid) throw new RuntimeException('Usuario inválido.');
            $st=$pdo->prepare('SELECT * FROM usuarios_sistema WHERE id_usuario=?'); $st->execute([$uid]); $target=$st->fetch(); if(!$target) throw new RuntimeException('Usuario no encontrado.');
            if(($target['rol']??'')==='superadministrador' && !is_superadmin()) throw new RuntimeException('No puedes modificar un superadministrador.');
            $name=trim((string)($_POST['nombre']??'')); $last=trim((string)($_POST['apellidos']??'')); $email=trim((string)($_POST['correo']??'')); $pass=(string)($_POST['password']??'');
            $role=(string)($_POST['rol']??'asesor'); $active=!empty($_POST['activo'])?1:0;
            if($uid===(int)$_SESSION['uid'] && !$active) throw new RuntimeException('No puedes desactivar tu propia cuenta.');
            $allowed=is_superadmin()?['superadministrador','administrador','asesor','consulta']:['administrador','asesor','consulta'];
            if(!in_array($role,$allowed,true)) throw new RuntimeException('Rol inválido.');
            if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Captura nombre y correo válidos.');
            $st=$pdo->prepare('SELECT COUNT(*) FROM usuarios_sistema WHERE correo=? AND id_usuario<>?'); $st->execute([$email,$uid]); if((int)$st->fetchColumn()>0) throw new RuntimeException('Ese correo ya está registrado.');
            $pdo->beginTransaction();
            if($pass!==''){
                if(strlen($pass)<8) throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
                $pdo->prepare('UPDATE usuarios_sistema SET nombre=?,apellidos=?,correo=?,rol=?,activo=?,password_hash=? WHERE id_usuario=?')->execute([$name,$last,$email,$role,$active,password_hash($pass,PASSWORD_DEFAULT),$uid]);
            } else {
                $pdo->prepare('UPDATE usuarios_sistema SET nombre=?,apellidos=?,correo=?,rol=?,activo=? WHERE id_usuario=?')->execute([$name,$last,$email,$role,$active,$uid]);
            }
            if($role!=='superadministrador') save_permissions($pdo,$uid,$_POST['ver']??[],$_POST['editar']??[]);
            $pdo->commit(); $_SESSION['flash']=['ok','Usuario actualizado.']; redirect(page_url('usuarios'));
        }
    } catch(Throwable $ex) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash']=['error',$ex->getMessage()]; redirect(page_url($page));
    }
}

// Sincroniza la clasificación antes de mostrar Customer Journey, contactos o mensajes.
if (in_array($page,['dashboard','contactos','mensajes'],true)) sync_customer_journey($pdo);

// Control de acceso a páginas.
$module=route_module($page);
if($module!==null && !can_module($pdo,$module,'ver')){
    http_response_code(403);
    $page='sin_permiso';
}
$currentUser=current_user($pdo);
$pageTitles=[
    'dashboard'=>'Dashboard','solicitudes'=>'Registros Aidores','solicitud'=>'Detalle del registro','asesor'=>'Solicitudes de asesor',
    'contactos'=>'Contactos','conversaciones'=>'Conversaciones','mensajes'=>'Mensajes','configuracion'=>'Configuración',
    'perfil'=>'Mi perfil','usuarios'=>'Usuarios','sin_permiso'=>'Sin permiso'
];

?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aidora CRM</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head><body class="app-body"><aside class="sidebar"><div class="side-logo"><img src="assets/aidora-logo.png" alt="Aidora"></div><nav><?=nav_link($pdo,'dashboard','Dashboard',$page,'dashboard')?><?=nav_link($pdo,'solicitudes','Registros Aidores',$page,'solicitudes')?><?=nav_link($pdo,'asesor','Solicitudes asesor',$page,'asesor')?><?=nav_link($pdo,'contactos','Contactos',$page,'contactos')?><?=nav_link($pdo,'conversaciones','Conversaciones',$page,'conversaciones')?><?=nav_link($pdo,'mensajes','Mensajes',$page,'mensajes')?><?=nav_link($pdo,'usuarios','Usuarios',$page,'usuarios')?><?=nav_link($pdo,'configuracion','Configuración',$page,'configuracion')?><a class="<?=$page==='perfil'?'active':''?>" href="<?=e(page_url('perfil'))?>">Mi perfil</a></nav><a class="logout" href="?logout=1">Cerrar sesión</a></aside><main class="content"><header class="topbar"><div><h1><?=e($pageTitles[$page]??ucfirst($page))?></h1><p>Aidora CRM</p></div><a class="user-pill user-pill-link" href="<?=e(page_url('perfil'))?>"><?php if(!empty($currentUser['foto_perfil'])): ?><img src="<?=e($currentUser['foto_perfil'])?>" alt="Perfil"><?php else: ?><span><?=e(initials($currentUser))?></span><?php endif; ?><b><?=e(trim(($currentUser['nombre']??'').' '.($currentUser['apellidos']??''))?:'Usuario')?></b></a></header><?php if($flash): ?><div class="flash <?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif; ?>

<?php if($page==='dashboard'):
    $counts=[
        'contactos'=>(int)$pdo->query('SELECT COUNT(*) FROM contactos')->fetchColumn(),
        'registros'=>(int)$pdo->query('SELECT COUNT(*) FROM solicitudes_aidores')->fetchColumn(),
        'revision'=>(int)$pdo->query("SELECT COUNT(*) FROM solicitudes_aidores WHERE estado IN ('nueva','en_revision')")->fetchColumn(),
        'asesor'=>(int)$pdo->query("SELECT COUNT(*) FROM solicitudes_asesor WHERE estado='nueva'")->fetchColumn(),
    ];
    $recent=$pdo->query('SELECT s.id_solicitud,s.nombre_completo,s.whatsapp,s.perfil_interes,s.zona_servicio,s.estado,s.creado_en FROM solicitudes_aidores s ORDER BY s.id_solicitud DESC LIMIT 8')->fetchAll();
    $profiles=$pdo->query("SELECT COALESCE(perfil_interes,'Sin definir') nombre,COUNT(*) total FROM solicitudes_aidores GROUP BY perfil_interes ORDER BY total DESC")->fetchAll();
    $zones=$pdo->query("SELECT COALESCE(zona_servicio,'Sin definir') nombre,COUNT(*) total FROM solicitudes_aidores GROUP BY zona_servicio ORDER BY total DESC LIMIT 8")->fetchAll();
    $journeyRows=$pdo->query("SELECT tipo_contacto,COUNT(*) total FROM contactos WHERE activo=1 GROUP BY tipo_contacto")->fetchAll();
    $journey=['prospecto'=>0,'prospecto_recurrente'=>0,'cliente'=>0,'cliente_recurrente'=>0]; foreach($journeyRows as $jr) $journey[$jr['tipo_contacto']]=(int)$jr['total'];
    $maxP=max(array_column($profiles,'total')?:[1]); $maxZ=max(array_column($zones,'total')?:[1]);
?>
<section class="cards"><div class="metric"><span>Contactos</span><strong><?=$counts['contactos']?></strong></div><div class="metric"><span>Registros Aidores</span><strong><?=$counts['registros']?></strong></div><div class="metric"><span>Por revisar</span><strong><?=$counts['revision']?></strong></div><div class="metric"><span>Solicitudes asesor</span><strong><?=$counts['asesor']?></strong></div></section>
<section class="panel journey-panel"><div class="panel-head journey-head"><div><h2>Customer Journey</h2><p>Clasificación actual de los contactos según consultas y registros aprobados.</p></div><span class="journey-note">Tipos de usuario</span></div><div class="journey-grid"><article><small>1</small><strong><?=$journey['prospecto']?></strong><h3>Prospecto</h3><p>Primeras 1 o 2 consultas y todavía sin registros aprobados.</p></article><article><small>2</small><strong><?=$journey['prospecto_recurrente']?></strong><h3>Prospecto recurrente</h3><p>A partir de la tercera consulta y todavía sin registros aprobados.</p></article><article><small>3</small><strong><?=$journey['cliente']?></strong><h3>Cliente</h3><p>Tiene entre 1 y 3 registros aprobados.</p></article><article><small>4</small><strong><?=$journey['cliente_recurrente']?></strong><h3>Cliente recurrente</h3><p>Tiene 4 o más registros aprobados.</p></article></div></section>
<section class="grid-2"><div class="panel"><div class="panel-head"><h2>Perfil de interés</h2></div><div class="bars"><?php if(!$profiles): ?><p class="empty">Aún no hay registros.</p><?php endif; foreach($profiles as $r): ?><div class="bar-row"><div class="bar-label"><span><?=e($r['nombre'])?></span><b><?=$r['total']?></b></div><div class="bar-track"><i style="width:<?=max(4,round(((int)$r['total']/$maxP)*100))?>%"></i></div></div><?php endforeach; ?></div></div><div class="panel"><div class="panel-head"><h2>Zonas de servicio</h2></div><div class="bars"><?php if(!$zones): ?><p class="empty">Aún no hay registros.</p><?php endif; foreach($zones as $r): ?><div class="bar-row"><div class="bar-label"><span><?=e($r['nombre'])?></span><b><?=$r['total']?></b></div><div class="bar-track"><i style="width:<?=max(4,round(((int)$r['total']/$maxZ)*100))?>%"></i></div></div><?php endforeach; ?></div></div></section>
<section class="panel"><div class="panel-head"><h2>Registros recientes</h2><a class="link" href="<?=e(page_url('solicitudes'))?>">Ver todos</a></div><div class="table-wrap"><table><thead><tr><th>Nombre</th><th>WhatsApp</th><th>Perfil</th><th>Zona</th><th>Estado</th><th>Fecha</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td><a class="row-link" href="<?=e(page_url('solicitud',['id'=>$r['id_solicitud']]))?>"><?=e($r['nombre_completo'])?></a></td><td><?=e($r['whatsapp'])?></td><td><?=e($r['perfil_interes']?:'—')?></td><td><?=e($r['zona_servicio']?:'—')?></td><td><span class="badge <?=e($r['estado'])?>"><?=e(status_label($r['estado']))?></span></td><td><?=e($r['creado_en'])?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif($page==='solicitudes'):
    $q=trim($_GET['q']??''); $estado=trim($_GET['estado']??''); $like='%'.$q.'%';
    $sql="SELECT * FROM solicitudes_aidores WHERE (?='' OR nombre_completo LIKE ? OR whatsapp LIKE ? OR correo LIKE ? OR ciudad LIKE ? OR zona_servicio LIKE ?)"; $args=[$q,$like,$like,$like,$like,$like];
    if(in_array($estado,['nueva','en_revision','contactado','aprobado','no_aprobado'],true)){ $sql.=' AND estado=?'; $args[]=$estado; }
    $sql.=' ORDER BY id_solicitud DESC'; $st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll();
?>
<section class="panel"><div class="panel-head panel-wrap"><h2>Registros de Aidores</h2><form class="search"><input type="hidden" name="page" value="solicitudes"><input name="q" value="<?=e($q)?>" placeholder="Nombre, WhatsApp, correo o zona"><select name="estado"><option value="">Todos los estados</option><?php foreach(['nueva','en_revision','contactado','aprobado','no_aprobado'] as $s): ?><option value="<?=$s?>" <?=$estado===$s?'selected':''?>><?=e(status_label($s))?></option><?php endforeach; ?></select><button>Filtrar</button></form></div><div class="table-wrap"><table><thead><tr><th>Nombre</th><th>WhatsApp</th><th>Perfil</th><th>Experiencia</th><th>Zona</th><th>Disponibilidad</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e($r['nombre_completo'])?></td><td><?=e($r['whatsapp'])?></td><td><?=e($r['perfil_interes']?:'—')?></td><td><?=e($r['experiencia_anios']?:'—')?></td><td><?=e($r['zona_servicio']?:'—')?></td><td><?=e(mb_strimwidth((string)$r['tipos_servicio'],0,45,'…'))?></td><td><span class="badge <?=e($r['estado'])?>"><?=e(status_label($r['estado']))?></span></td><td><a class="mini-btn" href="<?=e(page_url('solicitud',['id'=>$r['id_solicitud']]))?>">Ver detalle</a></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif($page==='solicitud'):
    $id=(int)($_GET['id']??0); $st=$pdo->prepare('SELECT s.*,c.id_contacto FROM solicitudes_aidores s JOIN contactos c ON c.id_contacto=s.id_contacto WHERE s.id_solicitud=?'); $st->execute([$id]); $r=$st->fetch();
    if(!$r): ?>
<section class="panel"><h2>Registro no encontrado</h2></section>
<?php else: ?>
<div class="detail-top"><div><a class="back-link" href="<?=e(page_url('solicitudes'))?>">← Regresar</a><h2><?=e($r['nombre_completo'])?></h2><p>Registro #<?=$r['id_solicitud']?> · <?=e($r['creado_en'])?></p></div><?php if(can_module($pdo,'solicitudes','editar')): ?><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_application_status"><input type="hidden" name="id" value="<?=$r['id_solicitud']?>"><select name="estado"><?php foreach(['nueva','en_revision','contactado','aprobado','no_aprobado'] as $s): ?><option value="<?=$s?>" <?=$r['estado']===$s?'selected':''?>><?=e(status_label($s))?></option><?php endforeach; ?></select><button class="primary">Guardar estado</button></form><?php endif; ?></div>
<section class="detail-grid"><article class="panel"><h2>Datos personales</h2><dl class="data-list"><div><dt>Nombre completo</dt><dd><?=e($r['nombre_completo'])?></dd></div><div><dt>Edad</dt><dd><?=e((string)$r['edad'])?></dd></div><div><dt>Teléfono</dt><dd><?=e($r['telefono_contacto']?:'—')?></dd></div><div><dt>WhatsApp automático</dt><dd><?=e($r['whatsapp'])?></dd></div><div><dt>Correo</dt><dd><?=e($r['correo']?:'—')?></dd></div><div><dt>Ciudad</dt><dd><?=e($r['ciudad']?:'—')?></dd></div><div><dt>Zona de servicio</dt><dd><?=e($r['zona_servicio']?:'—')?></dd></div></dl></article><article class="panel"><h2>Perfil y experiencia</h2><dl class="data-list"><div><dt>Perfil de interés</dt><dd><?=e($r['perfil_interes']?:'—')?></dd></div><div><dt>Nivel de estudios</dt><dd><?=e($r['nivel_estudios']?:'—')?></dd></div><div><dt>Formación relacionada</dt><dd><?=e($r['formacion_cuidado']?:'—')?></dd></div><div><dt>Años de experiencia</dt><dd><?=e($r['experiencia_anios']?:'—')?></dd></div><div class="wide"><dt>Actividades con experiencia</dt><dd><?=e($r['actividades_experiencia']?:'—')?></dd></div></dl></article><article class="panel"><h2>Disponibilidad y movilidad</h2><dl class="data-list"><div class="wide"><dt>Tipos de servicio</dt><dd><?=e($r['tipos_servicio']?:'—')?></dd></div><div class="wide"><dt>Días disponibles</dt><dd><?=e($r['dias_disponibles']?:'—')?></dd></div><div><dt>Movilidad</dt><dd><?=e($r['movilidad']?:'—')?></dd></div><div><dt>Distancia</dt><dd><?=e($r['distancia_traslado']?:'—')?></dd></div></dl></article><article class="panel"><h2>Motivación</h2><div class="long-answer"><b>¿Por qué quiere formar parte de Aidora?</b><p><?=nl2br(e($r['motivacion']?:'—'))?></p></div><div class="long-answer"><b>¿Qué significa cuidar a un adulto mayor?</b><p><?=nl2br(e($r['significado_cuidar']?:'—'))?></p></div></article></section>
<?php endif; ?>

<?php elseif($page==='asesor'):
    $rows=$pdo->query("SELECT a.*,c.nombre_completo,c.correo FROM solicitudes_asesor a JOIN contactos c ON c.id_contacto=a.id_contacto ORDER BY a.id_solicitud DESC")->fetchAll();
?>
<section class="panel"><div class="panel-head"><h2>Solicitudes para hablar con un asesor</h2></div><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Contacto</th><th>WhatsApp</th><th>Origen</th><th>Estado</th><?php if(can_module($pdo,'asesor','editar')): ?><th>Actualizar</th><?php endif; ?></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e($r['creado_en'])?></td><td><?=e($r['nombre_completo']?:'Sin nombre')?></td><td><?=e($r['whatsapp'])?></td><td><?=e($r['origen_solicitud'])?></td><td><span class="badge <?=e($r['estado'])?>"><?=e(status_label($r['estado']))?></span></td><?php if(can_module($pdo,'asesor','editar')): ?><td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_advisor_status"><input type="hidden" name="id" value="<?=$r['id_solicitud']?>"><select name="estado"><option value="nueva" <?=$r['estado']==='nueva'?'selected':''?>>Nueva</option><option value="contactado" <?=$r['estado']==='contactado'?'selected':''?>>Contactado</option><option value="cerrada" <?=$r['estado']==='cerrada'?'selected':''?>>Cerrada</option></select><button>Guardar</button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif($page==='contactos'):
    $q=trim($_GET['q']??''); $like='%'.$q.'%';
    $st=$pdo->prepare("SELECT c.*,(SELECT COUNT(*) FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto) total_registros,(SELECT estado FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto ORDER BY s.id_solicitud DESC LIMIT 1) ultimo_estado,(SELECT COUNT(*) FROM conversaciones cv WHERE cv.id_contacto=c.id_contacto) consultas FROM contactos c WHERE (?='' OR c.whatsapp LIKE ? OR c.nombre_completo LIKE ? OR c.correo LIKE ?) ORDER BY c.actualizado_en DESC");
    $st->execute([$q,$like,$like,$like]); $rows=$st->fetchAll();
?>
<section class="panel"><div class="panel-head panel-wrap"><h2>Contactos</h2><form class="search"><input type="hidden" name="page" value="contactos"><input name="q" value="<?=e($q)?>" placeholder="Buscar nombre, WhatsApp o correo"><button>Buscar</button></form></div><div class="table-wrap"><table><thead><tr><th>Nombre</th><th>WhatsApp</th><th>Tipo</th><th>Consultas</th><th>Correo</th><th>Registros</th><th>Último estado</th><th>Última interacción</th><?php if(can_module($pdo,'contactos','editar')): ?><th></th><?php endif; ?></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e($r['nombre_completo']?:'Sin nombre')?></td><td><?=e($r['whatsapp'])?></td><td><span class="journey-badge <?=e($r['tipo_contacto'])?>"><?=e(journey_label($r['tipo_contacto']))?></span></td><td><?=$r['consultas']?></td><td><?=e($r['correo']?:'—')?></td><td><?=$r['total_registros']?></td><td><?=e($r['ultimo_estado']?status_label($r['ultimo_estado']):'—')?></td><td><?=e($r['ultima_interaccion_en']?:$r['creado_en'])?></td><?php if(can_module($pdo,'contactos','editar')): ?><td><form method="post" onsubmit="return confirm('¿Eliminar este contacto y su historial?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_contact"><input type="hidden" name="id" value="<?=$r['id_contacto']?>"><button class="danger">Eliminar</button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif($page==='conversaciones'):
    $rows=$pdo->query("SELECT cv.*,c.nombre_completo,c.whatsapp,(SELECT contenido FROM interacciones i WHERE i.id_conversacion=cv.id_conversacion ORDER BY i.id_interaccion DESC LIMIT 1) ultimo FROM conversaciones cv JOIN contactos c ON c.id_contacto=cv.id_contacto ORDER BY cv.actualizado_en DESC LIMIT 250")->fetchAll();
?>
<section class="panel"><div class="panel-head"><h2>Conversaciones</h2></div><div class="table-wrap"><table><thead><tr><th>Contacto</th><th>WhatsApp</th><th>Estado</th><th>Último mensaje</th><th>Actualizada</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=e($r['nombre_completo']?:'Sin nombre')?></td><td><?=e($r['whatsapp'])?></td><td><span class="badge"><?=e($r['estado'])?></span></td><td><?=e(mb_strimwidth((string)$r['ultimo'],0,100,'…'))?></td><td><?=e($r['actualizado_en'])?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif($page==='mensajes'):
    $contacts=$pdo->query('SELECT id_contacto,nombre_completo,whatsapp,tipo_contacto FROM contactos WHERE activo=1 ORDER BY nombre_completo,whatsapp')->fetchAll();
    $hist=$pdo->query('SELECT * FROM mensajes_envios ORDER BY id_envio DESC LIMIT 60')->fetchAll();
    $jcounts=['prospecto'=>0,'prospecto_recurrente'=>0,'cliente'=>0,'cliente_recurrente'=>0];
    foreach($pdo->query('SELECT tipo_contacto,COUNT(*) total FROM contactos WHERE activo=1 GROUP BY tipo_contacto')->fetchAll() as $jr) $jcounts[$jr['tipo_contacto']]=(int)$jr['total'];
?>
<section class="message-layout"><div class="panel"><div class="panel-head"><div><h2>Nuevo mensaje</h2><p class="panel-sub">Envía la plantilla aprobada a un segmento o a una persona.</p></div></div><?php if(can_module($pdo,'mensajes','editar')): ?><form method="post" class="form-stack message-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="send_message"><div><label class="section-label">1. Grupo de destinatarios</label><div class="journey-segments"><?php foreach(['prospecto','prospecto_recurrente','cliente','cliente_recurrente'] as $jt): ?><button class="segment-card" type="button" data-segment="<?=e($jt)?>"><b><?=e(journey_label($jt))?></b><span><?=$jcounts[$jt]?> contactos</span></button><?php endforeach; ?></div><label class="compact-label">Otros segmentos<select name="segmento" id="segmento"><option value="individual">Contacto específico</option><optgroup label="Customer Journey"><option value="prospecto">Prospectos</option><option value="prospecto_recurrente">Prospectos recurrentes</option><option value="cliente">Clientes</option><option value="cliente_recurrente">Clientes recurrentes</option></optgroup><optgroup label="Aidora"><option value="todos">Todos los contactos</option><option value="registrados">Aidores registrados</option><option value="nueva">Registros nuevos</option><option value="en_revision">En revisión</option><option value="aprobado">Aprobados</option><option value="no_aprobado">No aprobados</option><option value="asesor">Solicitaron asesor</option></optgroup></select></label></div><div id="contacto-wrap" class="individual-search"><label class="section-label">2. Contacto específico</label><p>Busca por nombre o WhatsApp.</p><input type="search" id="contactSearch" placeholder="Buscar contacto por nombre o teléfono..."><select name="contacto" id="contactSelect"><option value="">Selecciona un contacto</option><?php foreach($contacts as $c): ?><option value="<?=$c['id_contacto']?>" data-text="<?=e(mb_strtolower(($c['nombre_completo']?:'').' '.$c['whatsapp']))?>"><?=e(($c['nombre_completo']?:$c['whatsapp']).' · '.$c['whatsapp'].' · '.journey_label($c['tipo_contacto']))?></option><?php endforeach; ?></select></div><label class="section-label">3. Mensaje<textarea name="mensaje" rows="7" maxlength="900" placeholder="Escribe aquí el mensaje que se insertará en {{1}} de la plantilla Aidora." required></textarea></label><div class="template-note"><b>Plantilla:</b> <?=e($config['template_name'])?> · <b>Idioma:</b> <?=e($config['template_language'])?></div><button class="primary" type="submit">Enviar plantilla</button></form><?php else: ?><p class="empty">Tienes permiso para consultar este módulo, pero no para realizar envíos.</p><?php endif; ?></div><div class="panel preview-sticky"><div class="panel-head"><h2>Vista previa</h2></div><div class="template-preview whatsapp-preview"><div class="wa-head"><div>A</div><span><b>Aidora</b><small>WhatsApp Business</small></span></div><div class="wa-body"><p>Hola, somos aidora tu apoyo para cuidar lo que más amas.</p><strong id="previewMessage">{{1}}</strong><p>Gracias, esperamos haber ayudado</p></div></div></div></section><section class="panel"><div class="panel-head"><h2>Envíos recientes</h2></div><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>WhatsApp</th><th>Mensaje</th><th>Estado</th><th>Detalle</th></tr></thead><tbody><?php foreach($hist as $r): ?><tr><td><?=e($r['creado_en'])?></td><td><?=e($r['whatsapp'])?></td><td><?=e(mb_strimwidth($r['mensaje'],0,70,'…'))?></td><td><span class="badge <?=e($r['estado'])?>"><?=e($r['estado'])?></span></td><td><?=e($r['detalle_error']?:'—')?></td></tr><?php endforeach; ?></tbody></table></div></section>
<script>
const s=document.getElementById('segmento'),w=document.getElementById('contacto-wrap'),search=document.getElementById('contactSearch'),sel=document.getElementById('contactSelect');
function sw(){if(w)w.style.display=s?.value==='individual'?'grid':'none';document.querySelectorAll('.segment-card').forEach(b=>b.classList.toggle('selected',b.dataset.segment===s?.value));}
s?.addEventListener('change',sw); sw();
document.querySelectorAll('.segment-card').forEach(b=>b.addEventListener('click',()=>{s.value=b.dataset.segment;sw();}));
if(search&&sel){const original=[...sel.options].map(o=>({value:o.value,text:o.textContent,search:o.dataset.text||''}));search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();const current=sel.value;sel.innerHTML='';original.forEach((o,i)=>{if(i===0||!q||o.search.includes(q)){const n=new Option(o.text,o.value);if(o.value===current)n.selected=true;sel.add(n);}});});}
const ta=document.querySelector('textarea[name="mensaje"]'),pv=document.getElementById('previewMessage');ta?.addEventListener('input',()=>{pv.textContent=ta.value||'{{1}}';});
</script>

<?php elseif($page==='perfil'):
    $u=current_user($pdo);
?>
<section class="profile-grid"><aside class="panel profile-card"><div class="avatar-xl"><?php if(!empty($u['foto_perfil'])): ?><img src="<?=e($u['foto_perfil'])?>" alt="Foto de perfil"><?php else: ?><span><?=e(initials($u))?></span><?php endif; ?></div><h2><?=e(trim(($u['nombre']??'').' '.($u['apellidos']??'')))?></h2><p><?=e(role_label((string)$u['rol']))?></p><form method="post" enctype="multipart/form-data" class="photo-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile_photo"><input type="file" name="foto" accept="image/jpeg,image/png,image/webp" required><button class="secondary" type="submit">Actualizar fotografía</button></form></aside><div><section class="panel profile-section"><div class="panel-head"><div><h2>Información personal</h2><p class="panel-sub">Datos visibles en el CRM</p></div></div><form method="post" class="profile-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile_update"><label>Nombre<input name="nombre" value="<?=e($u['nombre']??'')?>" required></label><label>Apellidos<input name="apellidos" value="<?=e($u['apellidos']??'')?>"></label><label class="full">Correo<input type="email" name="correo" value="<?=e($u['correo']??'')?>" required></label><div class="full form-actions"><button class="primary" type="submit">Guardar datos</button></div></form></section><section class="panel profile-section"><div class="panel-head"><div><h2>Seguridad</h2><p class="panel-sub">Cambia tu contraseña de acceso</p></div></div><form method="post" class="profile-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile_password"><label class="full">Contraseña actual<input type="password" name="actual" required></label><label>Nueva contraseña<input type="password" name="nueva" minlength="8" required></label><label>Confirmar contraseña<input type="password" name="confirmar" minlength="8" required></label><div class="full form-actions"><button class="primary" type="submit">Cambiar contraseña</button></div></form></section></div></section>

<?php elseif($page==='usuarios'):
    $users=$pdo->query('SELECT * FROM usuarios_sistema ORDER BY id_usuario ASC')->fetchAll();
    $editId=(int)($_GET['edit']??0); $editUser=null; $editPerm=[];
    if($editId){ $st=$pdo->prepare('SELECT * FROM usuarios_sistema WHERE id_usuario=?'); $st->execute([$editId]); $editUser=$st->fetch()?:null; if($editUser){$st=$pdo->prepare('SELECT modulo,puede_ver,puede_editar FROM usuario_permisos WHERE id_usuario=?');$st->execute([$editId]);foreach($st->fetchAll() as $pr)$editPerm[$pr['modulo']]=$pr;} }
    $mods=modules_catalog();
?>
<section class="panel"><div class="panel-head"><div><h2>Usuarios</h2><p class="panel-sub">Crea perfiles y define qué módulos pueden consultar o editar.</p></div><?php if(can_module($pdo,'usuarios','editar')): ?><button class="primary" type="button" onclick="document.getElementById('newUserDialog').showModal()">+ Nuevo usuario</button><?php endif; ?></div><div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Actualizado</th><?php if(can_module($pdo,'usuarios','editar')): ?><th></th><?php endif; ?></tr></thead><tbody><?php foreach($users as $usr): ?><tr><td><div class="user-cell"><div class="avatar-sm"><?php if(!empty($usr['foto_perfil'])): ?><img src="<?=e($usr['foto_perfil'])?>" alt=""><?php else: ?><span><?=e(initials($usr))?></span><?php endif; ?></div><b><?=e(trim($usr['nombre'].' '.($usr['apellidos']??'')))?></b></div></td><td><?=e($usr['correo'])?></td><td><?=e(role_label($usr['rol']))?></td><td><span class="badge <?=$usr['activo']?'aprobado':'no_aprobado'?>"><?=$usr['activo']?'Activo':'Inactivo'?></span></td><td><?=e($usr['actualizado_en'])?></td><?php if(can_module($pdo,'usuarios','editar')): ?><td><a class="mini-btn" href="<?=e(page_url('usuarios',['edit'=>$usr['id_usuario']]))?>">Editar</a></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>
<?php if($editUser && can_module($pdo,'usuarios','editar')): ?><section class="panel"><div class="panel-head"><div><h2>Editar usuario</h2><p class="panel-sub"><?=e($editUser['correo'])?></p></div><a class="mini-btn" href="<?=e(page_url('usuarios'))?>">Cerrar edición</a></div><form method="post" class="user-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="id_usuario" value="<?=$editUser['id_usuario']?>"><?php include __DIR__.'/partials/user_fields.php'; ?></form></section><?php endif; ?>
<?php if(can_module($pdo,'usuarios','editar')): ?><dialog id="newUserDialog" class="user-dialog"><div class="dialog-head"><h2>Nuevo usuario</h2><button type="button" onclick="this.closest('dialog').close()">×</button></div><form method="post" class="user-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_user"><?php $editUser=null;$editPerm=[]; include __DIR__.'/partials/user_fields.php'; ?></form></dialog><?php endif; ?>

<?php elseif($page==='configuracion'): ?>
<section class="panel"><div class="panel-head"><h2>Integración</h2></div><div class="settings-list"><div><span>Base de datos</span><strong><?=e($config['db']['name'])?></strong></div><div><span>Plantilla Meta</span><strong><?=e($config['template_name'])?></strong></div><div><span>Idioma</span><strong><?=e($config['template_language'])?></strong></div><div><span>Función CRM</span><strong><?=e($config['firebase_crm_url']?:'Pendiente de configurar')?></strong></div><div><span>API del bot</span><strong><?=e(rtrim($config['base_url'],'/').'/bot-api.php')?></strong></div></div></section>

<?php elseif($page==='sin_permiso'): ?>
<section class="panel"><h2>No tienes permiso para acceder a este módulo.</h2><p class="panel-sub">Solicita al administrador que habilite el permiso de Ver para esta sección.</p></section>
<?php else: ?><section class="panel"><h2>Página no encontrada</h2></section><?php endif; ?></main></body></html>
