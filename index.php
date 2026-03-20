<?php
$results = []; // 初期化
$planets = []; // 計算用の配列

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // --- 1. テキストエリアからのデータ抽出 ---
    if (!empty($_POST['astro_text'])) {
        $lines = explode("\n", $_POST['astro_text']);

        $name_map = [
            'Sun' => '太陽',
            'Moon' => '月',
            'Mercury' => '水星',
            'Venus' => '金星',
            'Mars' => '火星',
            'Jupiter' => '木星',
            'Saturn' => '土星',
            'Uranus' => '天王星',
            'Neptune' => '海王星',
            'Pluto' => '冥王星',
            'Mean Node' => 'ノード',
            'True Node' => 'ノード',
            'Juno' => 'ジュノー',
            'Ceres' => 'セレス',
            'Chiron' => 'キロン'
        ];

        $sign_modality_map = [
            'a' => 'cardinal',
            'd' => 'cardinal',
            'g' => 'cardinal',
            'j' => 'cardinal',
            'b' => 'fixed',
            'e' => 'fixed',
            'h' => 'fixed',
            'k' => 'fixed',
            'c' => 'mutable',
            'f' => 'mutable',
            'i' => 'mutable',
            'l' => 'mutable'
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            if (preg_match('/^[A-Z]\s+([A-Za-z\s]+?)\s+([a-l])\s+(\d+)°\s*(\d+)\'\s*(\d+)"/u', $line, $matches)) {
                $astro_name = trim($matches[1]);
                $sign_char = $matches[2];
                $deg = (int) $matches[3];
                $min = (int) $matches[4];
                $sec = (int) $matches[5];

                if (isset($name_map[$astro_name])) {
                    $label = $name_map[$astro_name];
                    $decimal_deg = $deg + ($min / 60) + ($sec / 3600);
                    $modality = $sign_modality_map[$sign_char] ?? 'cardinal';

                    $planets[$label] = [
                        'deg' => $decimal_deg,
                        'sign' => $modality
                    ];
                }
            }
        }
    }

    // --- 2. ASCとMCの手入力をマージ ---
    $manual_inputs = ['asc' => 'ASC', 'mc' => 'MC'];
    foreach ($manual_inputs as $post_key => $label) {
        if (isset($_POST[$post_key]) && $_POST[$post_key] !== '') {
            $planets[$label] = [
                'deg' => (float) $_POST[$post_key],
                // 【修正1】未入力時のエラー（Warning）対策
                'sign' => $_POST[$post_key . '_sign'] ?? 'cardinal'
            ];
        }
    }

    // --- 3. 既存の総当たり計算＆接触天体チェック ---
    $keys = array_keys($planets);
    $count = count($keys);

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $name1 = $keys[$i];
            $name2 = $keys[$j];
            $p1 = $planets[$name1];
            $p2 = $planets[$name2];

            $calc = hulfsum_calc($p1['deg'], $p1['sign'], $p2['deg'], $p2['sign']);

            // 接触天体（オーブ2度以内）を検索
            $hits1 = [];
            $hits2 = [];
            foreach ($planets as $p_name => $p_data) {
                // 軸を作っている本人（天体）は除外
                if ($p_name === $name1 || $p_name === $name2)
                    continue;

                // Point 1への接触チェック
                if (check_orb($calc[0]['deg'], $calc[0]['sign'], $p_data['deg'], $p_data['sign'], 2.0)) {
                    $hits1[] = $p_name;
                }
                // Point 2への接触チェック
                if (check_orb($calc[1]['deg'], $calc[1]['sign'], $p_data['deg'], $p_data['sign'], 2.0)) {
                    $hits2[] = $p_name;
                }
            }

            $results[] = [
                'pair' => "{$name1} / {$name2}",
                'result' => $calc,
                'hits1' => empty($hits1) ? 'なし' : implode(', ', $hits1),
                'hits2' => empty($hits2) ? 'なし' : implode(', ', $hits2)
            ];
        }
    }
}

// ハーフサム計算関数（そのまま）
function hulfsum_calc($a, $a_sign, $b, $b_sign)
{
    $calc_result = [];
    $sum = $a + $b;
    $half_deg = $sum / 2;

    $sign_matrix = [
        'cardinal' => ['cardinal' => 'cardinal', 'fixed' => 'mutable', 'mutable' => 'fixed'],
        'fixed' => ['cardinal' => 'mutable', 'fixed' => 'fixed', 'mutable' => 'cardinal'],
        'mutable' => ['cardinal' => 'fixed', 'fixed' => 'cardinal', 'mutable' => 'mutable'],
    ];

    $sign1 = $sign_matrix[$a_sign][$b_sign] ?? 'unknown';

    $calc_result[0] = ['deg' => $half_deg, 'sign' => $sign1];

    $deg2 = 0;
    $sign2 = '';

    if (($half_deg + 15) >= 30) {
        $deg2 = $half_deg + 15 - 30;
        if ($sign1 == 'cardinal') {
            $sign2 = 'mutable';
        } elseif ($sign1 == 'fixed') {
            $sign2 = 'cardinal';
        } elseif ($sign1 == 'mutable') {
            $sign2 = 'fixed';
        }
    } else {
        $deg2 = $half_deg + 15;
        if ($sign1 == 'cardinal') {
            $sign2 = 'fixed';
        } elseif ($sign1 == 'fixed') {
            $sign2 = 'mutable';
        } elseif ($sign1 == 'mutable') {
            $sign2 = 'cardinal';
        }
    }

    $calc_result[1] = ['deg' => $deg2, 'sign' => $sign2];

    return $calc_result;
}

// 90度ダイヤルでの距離計算＆オーブ判定関数
function check_orb($deg1, $sign1, $deg2, $sign2, $orb = 2.0)
{
    // 3区分を90度スケールにマッピング
    $get_90deg = function ($deg, $sign) {
        if ($sign === 'cardinal')
            return $deg;
        if ($sign === 'fixed')
            return $deg + 30;
        if ($sign === 'mutable')
            return $deg + 60;
        return $deg;
    };

    $v1 = $get_90deg($deg1, $sign1);
    $v2 = $get_90deg($deg2, $sign2);

    // 2点間の距離を計算（円周90度なので、45度を超える場合は逆回り）
    $diff = abs($v1 - $v2);
    if ($diff > 45) {
        $diff = 90 - $diff;
    }

    return $diff <= $orb;
}
?>

<?php if (!empty($results)): ?>
    <h2>計算結果</h2>
    <?php foreach ($results as $row): ?>
        <div class="result-box" style="margin-bottom: 15px; border: 1px solid #ccc; padding: 10px;">
            <div class="pair-title" style="font-weight: bold; font-size: 1.2em;">
                <?php echo htmlspecialchars($row['pair']); ?>
            </div>
            <ul style="list-style-type: none; padding-left: 0;">
                <li>ポイント1: <?php echo round($row['result'][0]['deg'], 2); ?>度
                    (<?php echo htmlspecialchars($row['result'][0]['sign']); ?>) ＝
                    <?php echo htmlspecialchars($row['hits1']); ?>
                </li>
                <li>ポイント2: <?php echo round($row['result'][1]['deg'], 2); ?>度
                    (<?php echo htmlspecialchars($row['result'][1]['sign']); ?>) ＝
                    <?php echo htmlspecialchars($row['hits2']); ?>
                </li>
            </ul>
        </div>
    <?php endforeach; ?>
    <hr>
<?php endif; ?>

<h1>ハーフサム計算機（オーブ2度判定付き）</h1>
<form method="POST">
    <p>Astrodienstのデータをここにコピペ：</p>
    <textarea name="astro_text" rows="10" cols="60" placeholder="A Sun a 3°22'24&quot; ..."></textarea>
    <br><br>

    <p>ASCとMC（必要な場合のみ入力）：</p>
    ASC : <input type="text" name="asc" size="5" />度
    <select name="asc_sign">
        <option value="cardinal">運動</option>
        <option value="fixed">定着</option>
        <option value="mutable">変通</option>
    </select>
    <br />
    MC : <input type="text" name="mc" size="5" />度
    <select name="mc_sign">
        <option value="cardinal">運動</option>
        <option value="fixed">定着</option>
        <option value="mutable">変通</option>
    </select>
    <br><br>

    <input type="submit" value="計算する" />
</form>