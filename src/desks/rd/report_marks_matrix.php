<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Coloured marks matrix for Report: Marks (Summary tab). Screen only; Word/PDF/Excel downloads are unchanged.
// Colour of each mark = mark / full mark of that form:  75%+ green, 50-74% amber, below 50% red, not marked grey.

function rmx_css(): string
{
    return '<style>
.rmx{border:1px solid #e3e6ef;border-radius:22px;background:#fff;overflow:hidden;margin-top:6px}
.rmx .rmxscroll{overflow-x:auto}
.rmx table{width:100%;border-collapse:collapse;min-width:960px}
.rmx thead th{background:#fbfbfa;color:#14171f;text-align:center;padding:18px 6px 14px;border-bottom:1px solid #e6e8ee;font-size:15px;font-weight:700;vertical-align:middle;line-height:1.35}
.rmx thead th .pc{display:block;font-weight:500;color:#6f7482;font-size:14px}
.rmx thead th.st{text-align:left;padding-left:24px;letter-spacing:.09em;text-transform:uppercase;font-size:14px}
.rmx thead th.tt,.rmx thead th.mk{letter-spacing:.09em;text-transform:uppercase;font-size:14px}
.rmx thead th.tt{border-left:1px solid #e6e8ee;border-right:0}
.rmx tbody td{padding:12px 6px;border-bottom:1px solid #e6e8ee;text-align:center;vertical-align:middle}
.rmx tbody tr:last-child td{border-bottom:0}
.rmx td.st{text-align:left;padding-left:24px}
.rmx td.st b{display:block;font-size:19px;color:#0f1220;font-weight:700}
.rmx td.st span{display:block;color:#5d6270;font-size:15px}
.rmx td.tt{border-left:1px solid #e6e8ee;font-size:25px;font-weight:700;color:#0f1220;white-space:nowrap}
.rmx .pill{display:inline-block;min-width:92px;padding:15px 0;border-radius:14px;font-size:21px;font-weight:600;line-height:1.2}
.rmx .pill.g{background:#d3e9cf;color:#2a6a1f}
.rmx .pill.a{background:#f4dfae;color:#7d5307}
.rmx .pill.r{background:#f4d4d4;color:#9c1f1f}
.rmx .pill.n{background:#f8f8f6;color:#9a9ea8;font-weight:400}
.rmx .mk .tag,.rmx td.mk .tag{display:inline-block;padding:4px 16px;border-radius:99px;font-size:17px;font-weight:500;white-space:nowrap}
.rmx .tag.g{background:#d3e9cf;color:#2a6a1f}
.rmx .tag.a{background:#f4dfae;color:#7d5307}
.rmx .tag.n{background:#f5f5f3;color:#4f5461}
.rmx .legend{display:flex;flex-wrap:wrap;gap:10px 26px;align-items:center;padding:16px 24px;border-top:1px solid #e6e8ee;background:#fff;font-size:17px;color:#3c414d}
.rmx .legend span{display:inline-flex;align-items:center;gap:9px}
.rmx .legend i{display:inline-block;width:20px;height:20px;border-radius:5px;border:1px solid transparent}
.rmx .legend i.g{background:#d3e9cf}.rmx .legend i.a{background:#f4dfae}.rmx .legend i.r{background:#f4d4d4}.rmx .legend i.n{background:#fbfbfa;border-color:#e3e6ef}
.rmx .emptyrow{padding:22px 24px;color:#6f7482}
.rmx .dt{display:block;font-size:12px;color:#6f7482;margin-top:5px;white-space:nowrap}.rmx .dt b{font-weight:500}.rmx td.st em{display:block;font-style:normal;color:#6f7482;font-size:13px;margin-top:2px;max-width:260px}.rmx td.tt small{display:block;font-size:12px;font-weight:400;color:#6f7482;margin-top:2px}
@media (max-width:700px){.rmx .pill{min-width:64px;font-size:17px;padding:11px 0}.rmx td.tt{font-size:20px}}
</style>';
}

// $s = the "summary" section built by marks_report(): row = [name, no, dept, supervisor, title, F4, F5, F6, F6 weighted, F7, F8, total, ['v' => 'n of 5']]
function rmx_matrix_html(array $s): string
{
    // [heading, sub-heading, column index in the summary row, full mark used for the colour]
    $cols = [
        ['Form 4', FORMS[4]['weight'] . '%', 5, 15.0],
        ['Form 5', FORMS[5]['weight'] . '%', 6, 5.0],
        ['Form 6', '100%', 7, 100.0],                           // as marked by the evaluator, out of 100
        ['Form 6', FORMS[6]['weight'] . '%', 8, (float)FORMS[6]['weight']],   // converted to its 30% share (used in the Total)
        ['Form 7', FORMS[7]['weight'] . '%', 9, 40.0],
        ['Form 8', FORMS[8]['weight'] . '%', 10, 10.0],
    ];
    $h = '<div class="rmx"><div class="rmxscroll"><table><thead><tr><th class="st">Student</th>';
    foreach ($cols as $c) { $h .= '<th>' . e($c[0]) . '<span class="pc">' . e($c[1]) . '</span></th>'; }
    $h .= '<th class="tt">Total<span class="pc" style="color:#14171f;font-weight:500">100%</span></th><th class="mk">Marked</th></tr></thead><tbody>';
    foreach ($s['rows'] as $row) {
        $sub = implode(' · ', array_filter([(string)($row[1] ?? ''), (string)($row[2] ?? '')], fn($x) => $x !== ''));
        $h .= '<tr><td class="st"><b>' . e((string)$row[0]) . '</b>' . ($sub !== '' ? '<span>' . e($sub) . '</span>' : '') . '</td>';
        foreach ($cols as $c) {
            $v = $row[$c[2]] ?? null;
            if ($v === null || $v === '') { $h .= '<td><span class="pill n">–</span></td>'; continue; }
            $pct = (float)$v / $c[3] * 100;
            $cls = $pct >= 75 ? 'g' : ($pct >= 50 ? 'a' : 'r');
            $h .= '<td><span class="pill ' . $cls . '" title="' . e(fmt_mark((float)$v) . ' out of ' . fmt_mark($c[3]) . ' (' . round($pct) . '%)') . '">' . e(rx_txt($v)) . '</span></td>';
        }
        $tot = $row[11] ?? null;
        $h .= '<td class="tt">' . ($tot === null || $tot === '' ? '<span style="color:#9a9ea8;font-weight:400">–</span>' : e(number_format((float)$tot, 1, '.', ''))) . '</td>';
        [$mv, $mc] = rx_cell($row[12] ?? null);
        $tc = $mc === 'green' ? 'g' : ($mc === 'amber' ? 'a' : 'n');
        $h .= '<td class="mk"><span class="tag ' . $tc . '">' . e((string)$mv) . '</span></td></tr>';
    }
    if (!$s['rows']) { $h .= '<tr><td colspan="9" class="emptyrow">No students to show.</td></tr>'; }
    $h .= '</tbody></table></div><div class="legend"><span><i class="g"></i>75% and above</span><span><i class="a"></i>50-74%</span><span><i class="r"></i>below 50%</span><span><i class="n"></i>not marked yet</span></div>'
        . '<div class="legend" style="padding-top:0;border-top:0;font-size:14px;color:#6f7482">Form 6 is marked out of 100 and then converted to ' . e((string)FORMS[6]['weight']) . '%. The Total adds Form 4, Form 5, Form 6 (' . e((string)FORMS[6]['weight']) . '% column), Form 7 and Form 8; the Form 6 (100%) column is not added again.</div></div>';
    return $h;
}

// Consolidated marks page: same matrix, built from rd_marks.php's $table records (keeps title of work, submission dates and "avg of N").
function rmx_consolidated_html(array $table, string $dept = ''): string
{
    $cols = [   // heading, sub-heading, form no, converted-to-30% column?, full mark used for the colour
        ['Form 4', FORMS[4]['weight'] . '%', 4, false, 15.0],
        ['Form 5', FORMS[5]['weight'] . '%', 5, false, 5.0],
        ['Form 6', '100%', 6, false, 100.0],
        ['Form 6', FORMS[6]['weight'] . '%', 6, true, (float)FORMS[6]['weight']],
        ['Form 7', FORMS[7]['weight'] . '%', 7, false, 40.0],
        ['Form 8', FORMS[8]['weight'] . '%', 8, false, 10.0],
    ];
    $h = '<div class="rmx"><div class="rmxscroll"><table><thead><tr><th class="st">Student</th>';
    foreach ($cols as $c) { $h .= '<th>' . e($c[0]) . '<span class="pc">' . e($c[1]) . '</span></th>'; }
    $h .= '<th class="tt">Total<span class="pc" style="color:#14171f;font-weight:500">100%</span></th><th class="mk">Marked</th></tr></thead><tbody>';
    foreach ($table as $r) {
        $s = $r['s'];
        $sub = implode(' · ', array_filter([(string)($s['student_no'] ?? ''), (string)($s['department'] ?? '')], fn($x) => $x !== ''));
        $title = (string)work_title($s);
        $h .= '<tr><td class="st"><b>' . e((string)$s['name']) . '</b>' . ($sub !== '' ? '<span>' . e($sub) . '</span>' : '') . ($title !== '' ? '<em>' . e($title) . '</em>' : '') . '</td>';
        foreach ($cols as $c) {
            $cell = $r['f'][$c[2]];
            if ($cell['avg'] === null) { $h .= '<td><span class="pill n">–</span></td>'; continue; }
            $v = $c[3] ? (float)weighted($c[2], (float)$cell['avg']) : (float)$cell['avg'];
            $pct = $v / $c[4] * 100;
            $cls = $pct >= 75 ? 'g' : ($pct >= 50 ? 'a' : 'r');
            $extra = '';
            if (!$c[3]) {
                if ($cell['count'] > 1) { $extra .= 'avg of ' . (int)$cell['count']; }
                if ($cell['date']) { $extra .= ($extra !== '' ? ' · ' : '') . fmt_day($cell['date']); }
            }
            $h .= '<td><span class="pill ' . $cls . '" title="' . e(fmt_mark($v) . ' out of ' . fmt_mark($c[4]) . ' (' . round($pct) . '%)') . '">' . e(fmt_mark($v)) . '</span>' . ($extra !== '' ? '<span class="dt">' . e($extra) . '</span>' : '') . '</td>';
        }
        $h .= '<td class="tt">' . ($r['n'] ? e(number_format((float)$r['total'], 1, '.', '')) : '<span style="color:#9a9ea8;font-weight:400">–</span>') . '</td>';
        $n = (int)$r['n']; $tc = $n >= 5 ? 'g' : ($n ? 'a' : 'n');
        $h .= '<td class="mk"><span class="tag ' . $tc . '">' . $n . ' of 5</span></td></tr>';
    }
    if (!$table) { $h .= '<tr><td colspan="9" class="emptyrow">No students' . ($dept !== '' ? ' in ' . e($dept) : '') . '.</td></tr>'; }
    $h .= '</tbody></table></div><div class="legend"><span><i class="g"></i>75% and above</span><span><i class="a"></i>50-74%</span><span><i class="r"></i>below 50%</span><span><i class="n"></i>not marked yet</span></div>'
        . '<div class="legend" style="padding-top:0;border-top:0;font-size:14px;color:#6f7482">Dates are when each form was submitted. Form 6 is marked out of 100 and converted to ' . e((string)FORMS[6]['weight']) . '%; the Total adds the Form 6 (' . e((string)FORMS[6]['weight']) . '%) column only.</div></div>';
    return $h;
}

// Download version of the Summary (Word / PDF / Excel): same layout as the screen matrix: coloured marks, Form 6 as 100% and 30%, Total, Marked, colour key.
function rmx_export_summary(array $s): array
{
    $forms = [[4, 5, 15.0, false], [5, 6, 5.0, false], [6, 7, 100.0, false], [6, 8, (float)FORMS[6]['weight'], true], [7, 9, 40.0, false], [8, 10, 10.0, false]];
    $cols = ['Student'];
    foreach ($forms as $f) { $cols[] = 'Form ' . $f[0] . "\n" . ($f[3] ? FORMS[6]['weight'] : ($f[0] === 6 ? 100 : FORMS[$f[0]]['weight'])) . '%'; }
    $cols[] = "Total\n100%"; $cols[] = 'Marked';
    $rows = [];
    foreach ($s['rows'] as $r) {
        $sub = implode(' · ', array_filter([(string)($r[1] ?? ''), (string)($r[2] ?? '')], fn($x) => $x !== ''));
        $row = [(string)$r[0] . ($sub !== '' ? "\n" . $sub : '')];
        foreach ($forms as $f) {
            $v = $r[$f[1]] ?? null;
            if ($v === null || $v === '') { $row[] = ['v' => '–', 'c' => 'gray']; continue; }
            $pct = (float)$v / $f[2] * 100;
            $row[] = ['v' => (float)$v, 'c' => $pct >= 75 ? 'green' : ($pct >= 50 ? 'amber' : 'red')];
        }
        $row[] = ($r[11] ?? null) === null ? ['v' => '–', 'c' => 'gray'] : round((float)$r[11], 1);
        $row[] = $r[12] ?? null;
        $rows[] = $row;
    }
    $s['cols'] = $cols; $s['rows'] = $rows;
    $s['note'] = 'Form 6 is marked out of 100 (Form 6 100% column) and converted to ' . FORMS[6]['weight'] . '% (Form 6 30% column). Total = Form 4 + Form 5 + Form 6 (' . FORMS[6]['weight'] . '% column) + Form 7 + Form 8, for the forms submitted so far; where two examiners marked a form the average is used. '
        . 'Colours: green = 75% and above, amber = 50-74%, red = below 50%, grey = not marked yet.';
    return $s;
}
