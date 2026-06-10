<?php
// ============================================================
//  LUMINA DENTAL — API REST Backend (index.php)
//  Requiere: composer require mongodb/mongodb
//  Servidor: PHP >= 7.4 con extensión mongodb (ext-mongodb)
// ============================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- DEPENDENCIA: instala con: composer require mongodb/mongodb ---
require_once __DIR__ . '/../vendor/autoload.php';

// ============================================================
//  CONFIGURACIÓN DE CONEXIÓN
// ============================================================
define('MONGO_URI', 'mongodb+srv://emiliamoh:qaws120987@cccc.fkjs72z.mongodb.net/?appName=cccc');
define('DB_NAME', 'lumina_dental');

// Colecciones disponibles
$COLECCIONES_PERMITIDAS = ['pacientes', 'odontologos', 'consultorios', 'citas'];

// ============================================================
//  CONEXIÓN A MONGODB ATLAS
// ============================================================
try {
    $client = new MongoDB\Client(MONGO_URI);
    $db     = $client->selectDatabase(DB_NAME);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo conectar a MongoDB: ' . $e->getMessage()]);
    exit();
}

// ============================================================
//  ENRUTADOR
//  GET    /index.php?col=pacientes          → listar todos
//  POST   /index.php?col=pacientes          → insertar uno
//  PUT    /index.php?col=pacientes&id=xxx   → actualizar uno
//  DELETE /index.php?col=pacientes&id=xxx   → eliminar uno
// ============================================================
$metodo    = $_SERVER['REQUEST_METHOD'];
$coleccion = isset($_GET['col']) ? trim($_GET['col']) : '';
$id        = isset($_GET['id'])  ? trim($_GET['id'])  : '';

// Validar colección
if (!in_array($coleccion, $COLECCIONES_PERMITIDAS)) {
    http_response_code(400);
    echo json_encode(['error' => "Colección '$coleccion' no permitida."]);
    exit();
}

$col = $db->selectCollection($coleccion);

// ---- Leer cuerpo JSON (para POST y PUT) --------------------
$body = [];
if (in_array($metodo, ['POST', 'PUT'])) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON inválido en el cuerpo de la petición.']);
        exit();
    }
}

// ============================================================
//  FUNCIÓN HELPER — Convertir _id de ObjectId a string
// ============================================================
function serializarDocumentos($cursor) {
    $resultado = [];
    foreach ($cursor as $doc) {
        $arr = (array) $doc;
        if (isset($arr['_id'])) {
            $arr['_id'] = (string) $arr['_id'];
        }
        $resultado[] = $arr;
    }
    return $resultado;
}

// ============================================================
//  CRUD
// ============================================================
try {

    // ----------------------------------------------------------
    //  GET — Listar / buscar
    // ----------------------------------------------------------
    if ($metodo === 'GET') {
        $filtro = [];

        // Búsqueda opcional por texto: ?q=nombre_a_buscar
        if (!empty($_GET['q'])) {
            $q = trim($_GET['q']);
            // Busca en los campos clave de cada colección
            $camposBusqueda = [
                'pacientes'    => ['nombre', 'id_paciente'],
                'odontologos'  => ['nombre', 'especialidad'],
                'consultorios' => ['nombre_medico', 'num_consultorio'],
                'citas'        => ['id_paciente', 'medico'],
            ];
            $campos = $camposBusqueda[$coleccion] ?? [];
            if (!empty($campos)) {
                $filtro['$or'] = array_map(fn($c) => [$c => new MongoDB\BSON\Regex($q, 'i')], $campos);
            }
        }

        $documentos = serializarDocumentos($col->find($filtro, ['sort' => ['_id' => 1]]));
        echo json_encode(['ok' => true, 'data' => $documentos]);
    }

    // ----------------------------------------------------------
    //  POST — Insertar nuevo documento
    // ----------------------------------------------------------
    elseif ($metodo === 'POST') {
        if (empty($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'Cuerpo vacío.']);
            exit();
        }
        // Quitar _id si lo manda el cliente (MongoDB lo genera)
        unset($body['_id']);

        $resultado = $col->insertOne($body);
        echo json_encode([
            'ok'  => true,
            'msg' => 'Registro insertado correctamente.',
            '_id' => (string) $resultado->getInsertedId()
        ]);
    }

    // ----------------------------------------------------------
    //  PUT — Actualizar documento por _id
    // ----------------------------------------------------------
    elseif ($metodo === 'PUT') {
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el parámetro ?id=']);
            exit();
        }
        // Quitar _id del body para no sobreescribirlo
        unset($body['_id']);

        $resultado = $col->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            ['$set' => $body]
        );
        echo json_encode([
            'ok'         => true,
            'msg'        => 'Registro actualizado.',
            'modificados' => $resultado->getModifiedCount()
        ]);
    }

    // ----------------------------------------------------------
    //  DELETE — Eliminar documento por _id
    // ----------------------------------------------------------
    elseif ($metodo === 'DELETE') {
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el parámetro ?id=']);
            exit();
        }
        $resultado = $col->deleteOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        echo json_encode([
            'ok'        => true,
            'msg'       => 'Registro eliminado.',
            'eliminados' => $resultado->getDeletedCount()
        ]);
    }

    else {
        http_response_code(405);
        echo json_encode(['error' => 'Método HTTP no soportado.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la operación: ' . $e->getMessage()]);
}
?>