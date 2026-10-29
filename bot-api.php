<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/back/config/config.php';

function respond(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function clean_phone(string $v): string { return preg_replace('/\D+/', '', $v) ?? ''; }
function db(): PDO {
    static $pdo = null; global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $pdo = new PDO(
        "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}",
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $pdo;
}

function refresh_customer_journey(PDO $pdo, int $contactId): void {
    $st=$pdo->prepare("SELECT COUNT(*) FROM solicitudes_aidores WHERE id_contacto=? AND estado='aprobado'");
    $st->execute([$contactId]); $approved=(int)$st->fetchColumn();
    $st=$pdo->prepare('SELECT COUNT(*) FROM conversaciones WHERE id_contacto=?');
    $st->execute([$contactId]); $consultas=(int)$st->fetchColumn();
    if($approved>=4) $tipo='cliente_recurrente';
    elseif($approved>=1) $tipo='cliente';
    elseif($consultas>=3) $tipo='prospecto_recurrente';
    else $tipo='prospecto';
    $pdo->prepare('UPDATE contactos SET tipo_contacto=? WHERE id_contacto=?')->execute([$tipo,$contactId]);
}

function ensure_context(PDO $pdo, string $wa): array {
    $wa = clean_phone($wa);
    if ($wa === '') throw new RuntimeException('WhatsApp requerido');

    $st = $pdo->prepare('SELECT * FROM contactos WHERE whatsapp=? LIMIT 1');
    $st->execute([$wa]);
    $contact = $st->fetch();
    if (!$contact) {
        $pdo->prepare("INSERT INTO contactos(whatsapp,origen,primera_interaccion_en,ultima_interaccion_en,activo) VALUES(?,'whatsapp',NOW(),NOW(),1)")->execute([$wa]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM contactos WHERE id_contacto=?');
        $st->execute([$id]);
        $contact = $st->fetch();
    } else {
        $pdo->prepare('UPDATE contactos SET ultima_interaccion_en=NOW(), activo=1 WHERE id_contacto=?')->execute([$contact['id_contacto']]);
    }

    $st = $pdo->prepare("SELECT * FROM conversaciones WHERE id_contacto=? AND estado='abierta' ORDER BY id_conversacion DESC LIMIT 1");
    $st->execute([$contact['id_contacto']]);
    $conv = $st->fetch();
    if (!$conv) {
        $pdo->prepare("INSERT INTO conversaciones(id_contacto,canal,estado) VALUES(?,'whatsapp','abierta')")->execute([$contact['id_contacto']]);
        $cid = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM conversaciones WHERE id_conversacion=?');
        $st->execute([$cid]);
        $conv = $st->fetch();
    }

    $st = $pdo->prepare('SELECT paso, datos_json FROM bot_sesiones WHERE whatsapp=? LIMIT 1');
    $st->execute([$wa]);
    $session = $st->fetch();
    $data = [];
    if ($session && !empty($session['datos_json'])) {
        $decoded = json_decode((string)$session['datos_json'], true);
        if (is_array($decoded)) $data = $decoded;
    }

    refresh_customer_journey($pdo, (int)$contact['id_contacto']);
    $st = $pdo->prepare('SELECT * FROM contactos WHERE id_contacto=?');
    $st->execute([$contact['id_contacto']]);
    $contact = $st->fetch() ?: $contact;

    return [
        'contact' => $contact,
        'conversation' => $conv,
        'session' => [
            'step' => $session['paso'] ?? 'menu',
            'data' => $data,
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error'=>'Método no permitido'], 405);
$key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
$expected = (string)($config['firebase_admin_key'] ?? '');
if ($expected === '' || !hash_equals($expected, (string)$key)) respond(['error'=>'No autorizado'], 401);

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) respond(['error'=>'JSON inválido'], 400);
$action = (string)($payload['action'] ?? '');
$wa = clean_phone((string)($payload['whatsapp'] ?? ''));
$pdo = db();

try {
    if ($action === 'bootstrap') {
        $ctx = ensure_context($pdo, $wa);
        $st = $pdo->prepare('SELECT id_solicitud,perfil_interes,nombre_completo,estado,creado_en FROM solicitudes_aidores WHERE id_contacto=? ORDER BY id_solicitud DESC LIMIT 1');
        $st->execute([$ctx['contact']['id_contacto']]);
        $ctx['latest_application'] = $st->fetch() ?: null;
        respond(['ok'=>true] + $ctx);
    }


    if ($action === 'message_status') {
        $mid = trim((string)($payload['message_id'] ?? ''));
        $status = trim((string)($payload['status'] ?? ''));
        if ($mid === '' || $status === '') respond(['ok'=>true]);
        $allowed = ['sent'=>'enviado','delivered'=>'entregado','read'=>'leido','failed'=>'error'];
        $dbStatus = $allowed[$status] ?? null;
        if ($dbStatus) {
            $detail = isset($payload['detail']) ? json_encode($payload['detail'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
            $pdo->prepare("UPDATE mensajes_envios SET estado=?, detalle_error=CASE WHEN ?='error' THEN ? ELSE detalle_error END, actualizado_en=NOW() WHERE meta_message_id=?")
                ->execute([$dbStatus,$dbStatus,$detail,$mid]);
        }
        respond(['ok'=>true]);
    }

    if ($action === 'log') {
        $contactId = (int)($payload['contact_id'] ?? 0);
        $conversationId = (int)($payload['conversation_id'] ?? 0);
        if (!$contactId || !$conversationId) {
            $ctx = ensure_context($pdo, $wa);
            $contactId = (int)$ctx['contact']['id_contacto'];
            $conversationId = (int)$ctx['conversation']['id_conversacion'];
        }
        $pdo->prepare('INSERT INTO interacciones(id_contacto,id_conversacion,direccion,tipo_mensaje,meta_message_id,paso_flujo,contenido,payload_json,estado_envio) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([
                $contactId, $conversationId,
                (string)($payload['direction'] ?? 'entrante'), (string)($payload['type'] ?? 'texto'),
                $payload['message_id'] ?? null, $payload['step'] ?? null,
                $payload['content'] ?? null,
                isset($payload['raw']) ? json_encode($payload['raw'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
                $payload['status'] ?? null,
            ]);
        $pdo->prepare('UPDATE conversaciones SET actualizado_en=NOW() WHERE id_conversacion=?')->execute([$conversationId]);
        respond(['ok'=>true,'id_interaccion'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'session_save') {
        $ctx = ensure_context($pdo, $wa);
        $step = trim((string)($payload['step'] ?? 'menu')) ?: 'menu';
        $data = $payload['data'] ?? [];
        if (!is_array($data)) $data = [];
        $pdo->prepare('INSERT INTO bot_sesiones(whatsapp,id_contacto,id_conversacion,paso,datos_json) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id_contacto=VALUES(id_contacto),id_conversacion=VALUES(id_conversacion),paso=VALUES(paso),datos_json=VALUES(datos_json),actualizado_en=NOW()')
            ->execute([$wa,$ctx['contact']['id_contacto'],$ctx['conversation']['id_conversacion'],$step,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        respond(['ok'=>true]);
    }

    if ($action === 'session_clear') {
        $pdo->prepare('DELETE FROM bot_sesiones WHERE whatsapp=?')->execute([$wa]);
        respond(['ok'=>true]);
    }

    if ($action === 'latest_registration') {
        $ctx = ensure_context($pdo, $wa);
        $st = $pdo->prepare('SELECT * FROM solicitudes_aidores WHERE id_contacto=? ORDER BY id_solicitud DESC LIMIT 1');
        $st->execute([$ctx['contact']['id_contacto']]);
        respond(['ok'=>true,'application'=>$st->fetch() ?: null]);
    }

    if ($action === 'save_advisor_request') {
        $ctx = ensure_context($pdo, $wa);
        $origin = trim((string)($payload['origin'] ?? 'menu_principal')) ?: 'menu_principal';
        $pdo->prepare("INSERT INTO solicitudes_asesor(id_contacto,id_conversacion,whatsapp,origen_solicitud,estado) VALUES(?,?,?,?, 'nueva')")
            ->execute([$ctx['contact']['id_contacto'],$ctx['conversation']['id_conversacion'],$wa,$origin]);
        respond(['ok'=>true,'id_solicitud'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'save_application') {
        $ctx = ensure_context($pdo, $wa);
        $d = $payload['data'] ?? [];
        if (!is_array($d)) $d = [];
        $name = trim((string)($d['nombre_completo'] ?? ''));
        if ($name === '') throw new RuntimeException('Falta nombre completo');

        $pdo->beginTransaction();
        $sql = 'INSERT INTO solicitudes_aidores(id_contacto,id_conversacion,perfil_interes,nombre_completo,edad,telefono_contacto,whatsapp,correo,ciudad,zona_servicio,nivel_estudios,formacion_cuidado,experiencia_anios,actividades_experiencia,tipos_servicio,dias_disponibles,movilidad,distancia_traslado,motivacion,significado_cuidar,estado) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'nueva\')';
        $pdo->prepare($sql)->execute([
            $ctx['contact']['id_contacto'], $ctx['conversation']['id_conversacion'],
            $d['perfil_interes'] ?? null, $name, $d['edad'] ?? null,
            $d['telefono_contacto'] ?? null, $wa, $d['correo'] ?? null,
            $d['ciudad'] ?? null, $d['zona_servicio'] ?? null,
            $d['nivel_estudios'] ?? null, $d['formacion_cuidado'] ?? null,
            $d['experiencia_anios'] ?? null, $d['actividades_experiencia'] ?? null,
            $d['tipos_servicio'] ?? null, $d['dias_disponibles'] ?? null,
            $d['movilidad'] ?? null, $d['distancia_traslado'] ?? null,
            $d['motivacion'] ?? null, $d['significado_cuidar'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE contactos SET nombre_completo=?, correo=?, ultima_interaccion_en=NOW() WHERE id_contacto=?')
            ->execute([$name,$d['correo'] ?? null,$ctx['contact']['id_contacto']]);
        $pdo->prepare('DELETE FROM bot_sesiones WHERE whatsapp=?')->execute([$wa]);
        $pdo->prepare("UPDATE conversaciones SET estado='finalizada', finalizada_en=NOW(), actualizado_en=NOW() WHERE id_conversacion=?")
            ->execute([$ctx['conversation']['id_conversacion']]);
        $pdo->commit();
        respond(['ok'=>true,'id_solicitud'=>$id]);
    }

    respond(['error'=>'Acción no reconocida'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error'=>$e->getMessage()], 500);
}
