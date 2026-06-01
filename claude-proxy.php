<?php
// Headers de segurança
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Carrega a chave da API
require_once __DIR__ . '/config.php';

if (!defined('CLAUDE_API_KEY') || CLAUDE_API_KEY === 'sua-chave-aqui') {
    http_response_code(500);
    echo json_encode(['error' => 'Servidor não configurado - chave API ausente']);
    exit;
}

// Obtém IP real do visitante
function getClientIp(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Limite de 4 usos por IP via MySQL
$clientIp = getClientIp();

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_usage (
        ip       VARCHAR(45)  NOT NULL PRIMARY KEY,
        count    INT          NOT NULL DEFAULT 0,
        first_at INT UNSIGNED NOT NULL,
        last_at  INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("SELECT count FROM ip_usage WHERE ip = ?");
    $stmt->execute([$clientIp]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && (int)$row['count'] >= 4) {
        http_response_code(429);
        echo json_encode(['error' => 'Você atingiu o limite de 4 análises gratuitas.']);
        exit;
    }

    $now = time();
    if ($row) {
        $pdo->prepare("UPDATE ip_usage SET count = count + 1, last_at = ? WHERE ip = ?")
            ->execute([$now, $clientIp]);
    } else {
        $pdo->prepare("INSERT INTO ip_usage (ip, count, first_at, last_at) VALUES (?, 1, ?, ?)")
            ->execute([$clientIp, $now, $now]);
    }
} catch (Exception $e) {
    // MySQL indisponível: não bloqueia o usuário, apenas registra o erro
    error_log('quiz-db error: ' . $e->getMessage());
}

// Rate limiting por sessão (anti-spam: 3 req/minuto)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$now = time();
if (!isset($_SESSION['claude_requests'])) {
    $_SESSION['claude_requests'] = [];
}
$_SESSION['claude_requests'] = array_values(array_filter(
    $_SESSION['claude_requests'],
    function ($t) use ($now) { return $t > $now - 60; }
));
if (count($_SESSION['claude_requests']) >= 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas requisições seguidas. Aguarde um momento.']);
    exit;
}
$_SESSION['claude_requests'][] = $now;

// Lê e valida o corpo da requisição
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Corpo da requisição inválido']);
    exit;
}

$validTemperaments = ['colérico', 'sanguíneo', 'fleumático', 'sentimental'];

$winner    = isset($input['winner'])    ? (string)$input['winner']    : '';
$secondKey = isset($input['secondKey']) ? (string)$input['secondKey'] : null;
$answers   = isset($input['answers'])   ? $input['answers']           : [];

if (!in_array($winner, $validTemperaments, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Temperamento dominante inválido']);
    exit;
}

if ($secondKey !== null && !in_array($secondKey, $validTemperaments, true)) {
    $secondKey = null;
}

if (!is_array($answers) || count($answers) !== 20) {
    http_response_code(400);
    echo json_encode(['error' => 'Respostas inválidas: esperado array com 20 elementos']);
    exit;
}

foreach ($answers as $i => $ans) {
    if ($ans !== null && (!is_int($ans) || $ans < 0 || $ans > 3)) {
        http_response_code(400);
        echo json_encode(['error' => 'Valor de resposta inválido na questão ' . ($i + 1)]);
        exit;
    }
}

// Perguntas e opções (cópia server-side — impede injeção de prompt pelo cliente)
$questions = [
    ['text' => 'Diante de um problema urgente, minha primeira reação é:', 'opts' => [
        'Agir imediatamente — pensar depois',
        'Analisar com calma todos os ângulos antes de mover',
        'Sentir o peso da situação e refletir longamente',
        'Esperar — raramente sinto urgência',
    ]],
    ['text' => 'Em grupos ou reuniões, eu naturalmente:', 'opts' => [
        'Assumo a liderança e direciono o rumo',
        'Animo o ambiente e falo bastante',
        'Ouço mais do que falo, observo as pessoas',
        'Fico quieto — prefiro passar despercebido',
    ]],
    ['text' => 'Minha vida emocional é:', 'opts' => [
        'Intensa e expressada abertamente',
        'Profunda, mas guardada para mim',
        'Estável — me abalo raramente',
        'Variável e comunicativa',
    ]],
    ['text' => 'Nas minhas horas livres, costumo:', 'opts' => [
        'Fazer algo produtivo — descansar sem fazer nada me incomoda',
        'Estar com pessoas, sair, conversar e me divertir',
        'Ler, criar, refletir ou ficar em silêncio comigo mesmo',
        'Descansar de verdade — sem culpa e sem pressa de fazer algo',
    ]],
    ['text' => 'Minha relação com novidades e mudanças é:', 'opts' => [
        'Adoro novidades — a rotina me entedia',
        'Prefiro estabilidade e previsibilidade',
        'Processo internamente antes de aceitar',
        'Gosto quando as mudanças partem de mim',
    ]],
    ['text' => 'Nos meus relacionamentos pessoais, tenho:', 'opts' => [
        'Muitos amigos, conexões fáceis mas nem sempre profundas',
        'Poucos vínculos, mas muito profundos e leais',
        'Relações cordiais mas sem grande intimidade',
        'Relações onde naturalmente lidero ou organizo',
    ]],
    ['text' => 'Quando fico com raiva ou sinto injustiça:', 'opts' => [
        'Explodo rapidamente — mas passo logo',
        'Guardo silenciosamente por muito tempo',
        'Raramente me irrito de forma séria',
        'Fico desconfortável mas esqueço rápido',
    ]],
    ['text' => 'Quando sou criticado ou humilhado:', 'opts' => [
        'Fico indignado — sinto que é injusto e reajo, mesmo que internamente',
        'Sinto vergonha ou constrangimento, mas supero e esqueço em pouco tempo',
        'Sinto profundamente e carrego isso por muito tempo — às vezes não esqueço',
        'Aceito com tranquilidade real — não fico agitado por dentro com isso',
    ]],
    ['text' => 'Ao tomar uma decisão importante:', 'opts' => [
        'Decido rápido e confio no instinto',
        'Analiso todos os detalhes antes de decidir',
        'Fico em dúvida e prefiro não decidir sozinho',
        'Decido com base no que me anima mais',
    ]],
    ['text' => 'Minha tendência perante fracassos é:', 'opts' => [
        'Supero rápido e sigo em frente',
        'Sinto fundo por bastante tempo — levo para o coração',
        'Aceito com tranquilidade sem grande drama',
        'Fico irritado e busco um culpado ou solução',
    ]],
    ['text' => 'Em situações de pressão ou crise:', 'opts' => [
        'Produzo melhor — a pressão me motiva',
        'Mantenho a calma e trabalho metodicamente',
        'Me fecho e processo tudo internamente',
        'Busco apoio e falo sobre o que estou sentindo',
    ]],
    ['text' => 'Minha mente costuma:', 'opts' => [
        'Estar focada em objetivos, metas e próximos passos',
        'Pular de assunto em assunto — cheia de ideias e planos dispersos',
        'Revisitar o passado, criar cenários e ruminar situações — difícil de desligar',
        'Funcionar de forma prática e linear — sem excessos de fantasia ou ruminação',
    ]],
    ['text' => 'Minha relação com regras e autoridade:', 'opts' => [
        'Questiono — prefiro liderar a ser liderado',
        'Sigo sem problemas — prefiro harmonia',
        'Respeito, mas avalio criticamente se faz sentido',
        'Sigo quando conveniente, questiono quando não',
    ]],
    ['text' => 'Minha vida interior é:', 'opts' => [
        'Rica e intensa — vivo muito dentro de mim',
        'Voltada para o externo — o mundo me interessa mais',
        'Estável e prática — não muito filosófica',
        'Agitada por objetivos e planos',
    ]],
    ['text' => 'Como me relaciono com o tempo:', 'opts' => [
        'Vivo o presente — o futuro não me preocupa muito',
        'Planejo cuidadosamente e olho para o futuro',
        'Fico preso ao passado — sinto muita nostalgia',
        'Vivo no ritmo — nem pressa nem atraso',
    ]],
    ['text' => 'Minha maior motivação na vida é:', 'opts' => [
        'Alcançar metas e deixar um legado',
        'Relacionamentos e experiências prazerosas',
        'Estabilidade, conforto e paz',
        'Significado profundo e conexão verdadeira',
    ]],
    ['text' => 'Quando alguém me magoa emocionalmente:', 'opts' => [
        'Confronto na hora e supero rápido',
        'Guardo mágoa por muito tempo, às vezes para sempre',
        'Fico chateado por um tempo e depois esqueço',
        'Aceito e sigo em frente sem grande drama',
    ]],
    ['text' => 'Em relação a projetos e compromissos assumidos, eu:', 'opts' => [
        'Começo com força e costumo terminar — não gosto de deixar coisas pela metade',
        'Começo com entusiasmo mas frequentemente não concluo — perco o interesse',
        'Planejo muito, executo devagar mas com qualidade e atenção aos detalhes',
        'Começo devagar mas sou consistente — raramente começo e raramente desisto',
    ]],
    ['text' => 'Se eu for honesto, minha tendência negativa mais recorrente é:', 'opts' => [
        'Impaciência, irritação fácil ou orgulho — quero que as coisas sejam do meu jeito',
        'Inconstância, vaidade ou necessidade de atenção e aprovação dos outros',
        'Tristeza, rancor ou autocrítica que não me deixa em paz',
        'Preguiça, apatia ou procrastinação — empurro as coisas com a barriga',
    ]],
    ['text' => 'Ao final de um dia intenso, eu:', 'opts' => [
        'Ainda tenho energia e quero socializar',
        'Preciso de silêncio para processar e me recarregar',
        'Me sinto tranquilo e continuo no mesmo ritmo',
        'Fico satisfeito se fui produtivo',
    ]],
];

$temps = [
    'apaixonado'  => ['name' => 'Apaixonado',   'badge' => 'Emotivo · Ativo · Secundário', 'e'=>1, 'a'=>1, 'r'=>1],
    'colérico'    => ['name' => 'Colérico',     'badge' => 'Emotivo · Ativo · Primário', 'e'=>1, 'a'=>1, 'r'=>-1],
    'sanguíneo'   => ['name' => 'Sanguíneo',    'badge' => 'Não-Emotivo · Ativo · Primário', 'e'=>-1, 'a'=>1, 'r'=>-1],
    'sentimental' => ['name' => 'Sentimental',  'badge' => 'Emotivo · Inativo · Secundário', 'e'=>1, 'a'=>-1, 'r'=>1],
    'apático'     => ['name' => 'Apático',      'badge' => 'Não-Emotivo · Inativo · Secundário', 'e'=>-1, 'a'=>-1, 'r'=>1],
    'fleumático'  => ['name' => 'Fleumático',   'badge' => 'Não-Emotivo · Ativo · Secundário', 'e'=>-1, 'a'=>1, 'r'=>1],
    'nervoso'     => ['name' => 'Nervoso',      'badge' => 'Emotivo · Inativo · Primário', 'e'=>1, 'a'=>-1, 'r'=>-1],
    'amorfo'      => ['name' => 'Amorfo',       'badge' => 'Não-Emotivo · Inativo · Primário', 'e'=>-1, 'a'=>-1, 'r'=>-1],
];

// Monta o texto das respostas
$answersText = '';
foreach ($questions as $i => $q) {
    $ai = $answers[$i] ?? null;
    if ($ai !== null && isset($q['opts'][$ai])) {
        $answersText .= '- ' . $q['text'] . ' Resposta: ' . $q['opts'][$ai] . "\n";
    }
}

$t  = $temps[$winner];
$t2 = $secondKey ? $temps[$secondKey] : null;

$prompt =
    "Você é um especialista em temperamentos humanos com profundo conhecimento do sistema Heymans-Le Senne (8 temperamentos baseados em 3 eixos) e das obras de Hipócrates, Padre Paulo Ricardo, Pe. Antonio Royo Marín, Pe. José Antonio Gonzalez e Murilo Frizanco.\n\n" .
    "SISTEMA DOS 8 TEMPERAMENTOS (Heymans-Le Senne):\n" .
    "Baseado em 3 eixos:\n" .
    "- Emotividade (E+ emotivo vs E- não-emotivo): reatividade emocional\n" .
    "- Atividade (A+ ativo vs A- inativo): ritmo de ação e energia\n" .
    "- Ressonância (S secundário vs P primário): duração/impacto da emoção\n\n" .
    "Os 8 tipos:\n" .
    "1. Apaixonado (E+A+S): Ardente, tenacioso, líder com força de vontade - defeito: ira e rancor\n" .
    "2. Colérico (E+A+P): Impulsivo, aventureiro, enérgico - defeito: ira e superficialidade\n" .
    "3. Sanguíneo (E-A+P): Alegre, comunicativo, adaptável - defeito: inconstância e vaidade\n" .
    "4. Sentimental (E+A-S): Profundo, reflexivo, criativo - defeito: tristeza e autocrítica\n" .
    "5. Apático (E-A-S): Tranquilo, observador, sábio - defeito: apatia e desinteresse\n" .
    "6. Fleumático (E-A+S): Confiável, paciente, constante - defeito: acídia e preguiça\n" .
    "7. Nervoso (E+A-P): Sensível, artístico, compassivo - defeito: sensibilidade que escraviza\n" .
    "8. Amorfo (E-A-P): Influenciável, versátil, descompromissado - defeito: preguiça e negligência\n\n" .
    "O usuário fez um quiz com 20 perguntas sobre esses eixos e obteve:\n" .
    "- Tipo primário: {$t['name']} ({$t['badge']})\n" .
    ($t2 ? "- Influência secundária: {$t2['name']}\n" : '') .
    "\nRespostas às 20 perguntas:\n{$answersText}" .
    "\nGere uma análise PERSONALIZADA e CONCISA com base nas respostas acima. Cite padrões específicos das respostas, mencione o defeito dominante com compaixão, reconheça as qualidades únicas. Use português brasileiro caloroso. Mantenha cada campo curto (máximo 3 frases) para caber no limite de tokens.\n" .
    'Responda APENAS em JSON válido sem markdown, sem texto antes ou depois:' . "\n" .
    '{"perfil":"2 frases sobre o perfil único — cite padrões concretos das respostas","cotidiano":"2 frases sobre como aparece no trabalho e relacionamentos","desafios":"2 frases sobre maiores desafios — mencione o defeito dominante com compaixão","crescimento":"2 frases com caminhos de crescimento específicos a este tipo","pessoas":[{"emoji":"emoji","nome":"Nome histórico ou santo com este temperamento","descricao":"1 frase sobre como o temperamento aparece nesta pessoa"},{"emoji":"emoji","nome":"Segundo nome","descricao":"1 frase"},{"emoji":"emoji","nome":"Terceiro nome","descricao":"1 frase"}]}';

// Chama a API da Anthropic
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL não disponível neste servidor']);
    exit;
}

$requestBody = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 2500,
    'messages'   => [['role' => 'user', 'content' => $prompt]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha na conexão com a API: ' . $curlError]);
    exit;
}

http_response_code($httpCode);
echo $response;
