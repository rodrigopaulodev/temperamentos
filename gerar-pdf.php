<?php
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['payload'])) {
    http_response_code(400); echo '<p>Dados inválidos.</p>'; exit;
}

$raw = json_decode($_POST['payload'], true);
if (!$raw || empty($raw['winner'])) { http_response_code(400); exit; }

$valid = ['colérico','sanguíneo','fleumático','sentimental'];
$winner    = in_array($raw['winner'], $valid, true) ? $raw['winner'] : null;
$secondKey = isset($raw['secondKey']) && in_array($raw['secondKey'], $valid, true) ? $raw['secondKey'] : null;
$scores    = isset($raw['scores']) && is_array($raw['scores']) ? $raw['scores'] : [];
$ai        = isset($raw['ai'])     && is_array($raw['ai'])     ? $raw['ai']     : [];
if (!$winner) { http_response_code(400); exit; }

function e(string $s): string {
    return htmlspecialchars(strip_tags($s), ENT_QUOTES, 'UTF-8');
}

$temps = [
    'colérico' => [
        'name'=>'Colérico','emoji'=>'🔥','badge'=>'Extrovertido · Emocional · Ativo',
        'color'=>'#C0392B','light'=>'#FFE0E0','accent'=>'#E74C3C',
        'desc'=>'Você é movido pela ação. Tem liderança natural, energia inesgotável e visão clara de onde quer chegar. Humor hipocrático: bílis amarela. Defeito dominante: ira e orgulho. Virtude a cultivar: mansidão. Santos com este temperamento: São Paulo Apóstolo, Santa Teresa d\'Ávila.',
        'pos'=>['Liderança natural','Determinação e foco','Alta energia e proatividade','Coragem para decidir','Visão estratégica'],
        'neg'=>['Impaciência com os outros','Dificuldade em ouvir','Tendência ao autoritarismo','Explosivo em conflitos','Orgulho excessivo'],
    ],
    'sanguíneo' => [
        'name'=>'Sanguíneo','emoji'=>'☀️','badge'=>'Extrovertido · Não-Emocional · Ativo',
        'color'=>'#D35400','light'=>'#FFF0C0','accent'=>'#E67E22',
        'desc'=>'Você é alegria em movimento. Faz amigos com facilidade, traz vida a qualquer ambiente e enfrenta tudo com otimismo genuíno. Humor hipocrático: sangue. Defeito dominante: inconstância e vaidade. Virtude a cultivar: perseverança e vida interior. Santos com este temperamento: São Pedro Apóstolo, São Filipe Néri.',
        'pos'=>['Otimismo contagiante','Facilidade de comunicação','Adaptabilidade e flexibilidade','Cria laços com facilidade','Generosidade espontânea'],
        'neg'=>['Superficialidade nos vínculos','Dificuldade em manter foco','Inconstância emocional','Promete mais do que cumpre','Busca excessiva de aprovação'],
    ],
    'fleumático' => [
        'name'=>'Fleumático','emoji'=>'🌊','badge'=>'Introvertido · Não-Emocional · Inativo',
        'color'=>'#1A5276','light'=>'#C8F0E8','accent'=>'#1ABC9C',
        'desc'=>'Você é o ancoradouro. Calmo, confiável e paciente, oferece estabilidade a todos ao redor. Humor hipocrático: fleuma. Defeito dominante: acídia e ociosidade. Virtude a cultivar: fervor e diligência. Santos com este temperamento: São João Maria Vianney, São Tomás de Aquino.',
        'pos'=>['Estabilidade emocional','Confiabilidade e constância','Paciência e prudência','Mediador natural de conflitos','Persistência silenciosa'],
        'neg'=>['Resistência a mudanças','Passividade e procrastinação','Dificuldade de motivação','Pode parecer indiferente','Tédio espiritual (acídia)'],
    ],
    'sentimental' => [
        'name'=>'Melancólico','emoji'=>'🌙','badge'=>'Introvertido · Emocional · Inativo',
        'color'=>'#4A235A','light'=>'#EDE0FF','accent'=>'#9B59B6',
        'desc'=>'Você tem uma vida interior extraordinariamente rica. Sente o mundo com intensidade rara e cria vínculos profundos. Humor hipocrático: bílis negra. Defeito dominante: tristeza e egocentrismo. Virtude a cultivar: esperança e amor ao próximo. Santos com este temperamento: Santa Teresinha do Menino Jesus, São João Apóstolo.',
        'pos'=>['Profundidade emocional rara','Empatia intensa','Senso estético elevado','Lealdade e comprometimento profundos','Riqueza criativa e artística'],
        'neg'=>['Tendência ao pessimismo','Dificuldade em superar mágoas','Autocrítica excessiva','Isolamento e introversão extrema','Paralisia emocional'],
    ],
];

$t  = $temps[$winner];
$t2 = $secondKey ? ($temps[$secondKey] ?? null) : null;

arsort($scores);
$maxScore = !empty($scores) ? max(array_values($scores)) : 1;
$barColors = ['colérico'=>'#E74C3C','sanguíneo'=>'#E67E22','fleumático'=>'#1ABC9C','sentimental'=>'#9B59B6'];
$barEmojis = ['colérico'=>'🔥','sanguíneo'=>'☀️','fleumático'=>'🌊','sentimental'=>'🌙'];
$barNames  = ['colérico'=>'Colérico','sanguíneo'=>'Sanguíneo','fleumático'=>'Fleumático','sentimental'=>'Melancólico'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Temperamento <?= e($t['name']) ?> — Quiz dos Temperamentos</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,Arial,sans-serif;background:#fff;color:#1a1830;font-size:13.5px;line-height:1.65}
.wrap{max-width:680px;margin:0 auto;padding:36px 40px}

/* Cabeçalho */
.header{background:<?= $t['light'] ?>;border:1.5px solid <?= $t['accent'] ?>44;border-radius:14px;padding:28px 24px 20px;text-align:center;margin-bottom:22px}
.site-tag{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#7f77dd;margin-bottom:10px}
.emoji{font-size:2.6rem;display:block;margin-bottom:6px}
.sub-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.13em;color:#6e6c85;margin-bottom:4px}
.temp-name{font-size:2rem;font-weight:800;color:<?= $t['color'] ?>;margin-bottom:6px;letter-spacing:-0.5px}
.badge{display:inline-block;background:<?= $t['accent'] ?>22;color:<?= $t['color'] ?>;border:1px solid <?= $t['accent'] ?>55;border-radius:99px;padding:3px 12px;font-size:10.5px;font-weight:600;margin-bottom:12px}
.temp-desc{font-size:12px;color:#3e3c5a;line-height:1.75;max-width:520px;margin:0 auto}

/* Secundário */
.secondary{border-left:4px solid <?= $t2 ? $t2['accent'] : '#ccc' ?>;background:#f8f6ff;padding:11px 14px;border-radius:0 8px 8px 0;margin-bottom:20px}
.sec-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9090aa;margin-bottom:3px}
.sec-name{font-size:1rem;font-weight:700;color:<?= $t2 ? $t2['color'] : '#333' ?>;margin-bottom:3px}
.sec-desc{font-size:11.5px;color:#4a4868;line-height:1.65}

/* Divisória */
hr{border:none;border-top:1px solid #e8e5f4;margin:18px 0}

/* 2 colunas pontos */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
@media(max-width:480px){.two-col{grid-template-columns:1fr}}
.col-title{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9090aa;margin-bottom:8px}
.pf{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#1a1830;margin-bottom:6px;line-height:1.45}
.dot{width:7px;height:7px;min-width:7px;border-radius:50%;margin-top:4px}
.dot-g{background:#1D9E75}.dot-r{background:#D85A30}

/* IA */
.ai-title{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#534AB7;margin-bottom:5px}
.ai-text{font-size:12.5px;color:#2d2b45;line-height:1.75;margin-bottom:16px}
.person{display:flex;gap:10px;background:#f4f2ff;border-radius:8px;padding:10px 12px;margin-bottom:8px}
.p-emoji{font-size:1.4rem;flex-shrink:0;line-height:1}
.p-name{font-weight:700;font-size:12px;color:#1a1830;margin-bottom:2px}
.p-desc{font-size:11px;color:#4a4868;line-height:1.6}

/* Scores */
.scores-title{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9090aa;margin-bottom:10px}
.score-row{display:flex;align-items:center;gap:9px;margin-bottom:7px}
.s-label{width:105px;font-size:12px;color:#1a1830;white-space:nowrap}
.s-track{flex:1;height:7px;background:#e8e5f4;border-radius:4px;overflow:hidden}
.s-fill{height:7px;border-radius:4px}
.s-pts{font-size:10.5px;color:#9090aa;width:30px;text-align:right}

/* Footer */
.footer{text-align:center;font-size:10px;color:#9090aa;margin-top:22px;padding-top:14px;border-top:1px solid #e8e5f4;line-height:1.8}
.footer strong{color:#534AB7}

/* Print — margin:0 remove header/footer automático do browser */
@page{size:A4;margin:0}
@media print{
  body{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .wrap{padding:12mm 15mm}
  .header{break-inside:avoid}
  .two-col{break-inside:avoid}
  .person{break-inside:avoid}
}
</style>
</head>
<body>
<div class="wrap">

  <!-- Cabeçalho -->
  <div class="header">
    <div class="site-tag">Quiz dos Temperamentos</div>
    <span class="emoji"><?= $t['emoji'] ?></span>
    <div class="sub-label">Temperamento Dominante</div>
    <div class="temp-name"><?= e($t['name']) ?></div>
    <div class="badge"><?= e($t['badge']) ?></div>
    <p class="temp-desc"><?= e($t['desc']) ?></p>
  </div>

  <?php if ($t2): ?>
  <!-- Temperamento secundário -->
  <div class="secondary">
    <div class="sec-label">Com influência de</div>
    <div class="sec-name"><?= $t2['emoji'] ?> <?= e($t2['name']) ?></div>
    <p class="sec-desc"><?= e($t2['desc']) ?></p>
  </div>
  <?php endif; ?>

  <hr>

  <!-- Pontos fortes / A desenvolver -->
  <div class="two-col">
    <div>
      <div class="col-title">Pontos Fortes</div>
      <?php foreach ($t['pos'] as $item): ?>
      <div class="pf"><div class="dot dot-g"></div><?= e($item) ?></div>
      <?php endforeach; ?>
    </div>
    <div>
      <div class="col-title">A Desenvolver</div>
      <?php foreach ($t['neg'] as $item): ?>
      <div class="pf"><div class="dot dot-r"></div><?= e($item) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!empty($ai)): ?>
  <hr>
  <!-- Análise da IA -->
  <?php foreach ($ai as $sec): ?>
    <?php if (empty($sec['persons'])): ?>
    <div class="ai-title"><?= e($sec['title']) ?></div>
    <div class="ai-text"><?= nl2br(e($sec['text'])) ?></div>
    <?php else: ?>
    <div style="break-before:page;padding-top:4mm">
    <div class="ai-title"><?= e($sec['title']) ?></div>
    <?php foreach ($sec['persons'] as $p): ?>
    <div class="person">
      <div class="p-emoji"><?= $p['emoji'] ?></div>
      <div>
        <div class="p-name"><?= e($p['name']) ?></div>
        <div class="p-desc"><?= e($p['desc']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($scores)): ?>
  <hr>
  <!-- Gráfico de temperamentos -->
  <div class="scores-title">Todos os Temperamentos</div>
  <?php foreach ($scores as $key => $val):
    if (!isset($barColors[$key])) continue;
    $pct = $maxScore > 0 ? round(($val / $maxScore) * 100) : 0;
  ?>
  <div class="score-row">
    <div class="s-label"><?= $barEmojis[$key] ?> <?= $barNames[$key] ?></div>
    <div class="s-track"><div class="s-fill" style="width:<?= $pct ?>%;background:<?= $barColors[$key] ?>"></div></div>
    <div class="s-pts"><?= (int)$val ?>pt</div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="footer">
    Baseado em Hipócrates · Eysenck · Le Gall · Nemi<br>
    <strong>Desenvolvido por Rodrigo Paulo</strong> · akutis.com.br/temperamentos
  </div>

</div>
<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 600);
};
</script>
</body>
</html>
