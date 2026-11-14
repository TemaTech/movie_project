<?php

namespace App\Services\Series;

class SeriesMembership
{
    /**
     * Wikipediaのシリーズ名に対する追加の所属判定用エイリアス。
     * 短い接頭辞マッチは使わず、ここに書いたものだけを足す。
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_ALIASES = [
        '007' => ['007', 'ジェームズ・ボンド', 'James Bond'],
        'ジュラシック・パーク' => ['ジュラシック・パーク', 'ジュラシック・ワールド', 'Jurassic Park', 'Jurassic World'],
        '男はつらいよ' => ['男はつらいよ'],
        'スター・ウォーズ' => ['スター・ウォーズ', 'スターウォーズ', 'Star Wars'],
        'ハリー・ポッター' => ['ハリー・ポッター', 'Harry Potter'],
        'パイレーツ・オブ・カリビアン' => ['パイレーツ・オブ・カリビアン', 'パイレーツオブカリビアン'],
        'バック・トゥ・ザ・フューチャー' => ['バック・トゥ・ザ・フューチャー', 'バックトゥザフューチャー'],
        'インディ・ジョーンズ' => ['インディ・ジョーンズ', 'インディジョーンズ'],
        'ロード・オブ・ザ・リング' => ['ロード・オブ・ザ・リング'],
        'ホビット' => ['ホビット'],
        'ターミネーター' => ['ターミネーター'],
        'ゴジラ' => ['ゴジラ'],
        '仮面ライダー' => ['仮面ライダー'],
        '機動戦士ガンダム' => ['ガンダム'],
        '鬼滅の刃' => ['鬼滅の刃'],
        'STAND BY ME ドラえもん' => ['STAND BY ME ドラえもん', 'STAND BY MEドラえもん'],
        '銀魂（実写）' => ['銀魂'],
        '銀魂（アニメ）' => ['劇場版 銀魂', '劇場版銀魂'],
        'スパイダーマン' => ['スパイダーマン'],
        'ソニーズ・スパイダーマン・ユニバース' => ['スパイダーマン:ノー・ウェイ・ホーム', 'スパイダーマン:ホームカミング', 'スパイダーマン:ファー・フロム・ホーム'],
        'マーベル・シネマティック・ユニバース' => ['アベンジャーズ', 'アイアンマン', 'キャプテン・アメリカ', 'ガーディアンズ・オブ・ギャラクシー', 'アントマン', 'ドクター・ストレンジ', 'ブラックパンサー', 'ソー:', 'マイティ・ソー'],
        'DCエクステンデッド・ユニバース' => ['バットマン vs スーパーマン', 'ワンダーウーマン', 'アクアマン', 'シャザム', 'ジャスティス・リーグ', 'ザ・スーサイド・スクワッド'],
        'ファンタスティック・ビースト' => ['ファンタスティック・ビースト'],
        '新世紀エヴァンゲリオン' => ['エヴァンゲリオン', 'シン・エヴァンゲリオン'],
        'モンスターズ・インク' => ['モンスターズ'],
        'ファインディング・ニモ' => ['ファインディング'],
        'トイ・ストーリー' => ['トイ・ストーリー'],
        'ズートピア' => ['ズートピア'],
        'モアナと伝説の海' => ['モアナ'],
        'インサイド・ヘッド' => ['インサイド・ヘッド'],
        'アナと雪の女王' => ['アナと雪の女王', 'アナ雪'],
        'ワイルド・スピード' => ['ワイルド・スピード'],
        'ミッション:インポッシブル' => ['ミッション:インポッシブル', 'ミッション：インポッシブル'],
        'トランスフォーマー' => ['トランスフォーマー'],
        'バイオハザード' => ['バイオハザード'],
        'エイリアン' => ['エイリアン', 'プロメテウス'],
        'ロッキー' => ['ロッキー', 'クリード'],
        'ランボー' => ['ランボー'],
        'オーシャンズ11' => ['オーシャンズ'],
        'ナイト ミュージアム' => ['ナイト ミュージアム', 'ナイトミュージアム'],
        '猿の惑星（リブート）' => ['猿の惑星'],
        'NARUTO -ナルト-' => ['NARUTO', 'ナルト'],
        '僕のヒーローアカデミア' => ['僕のヒーローアカデミア', 'ヒロアカ'],
        '呪術廻戦' => ['呪術廻戦'],
        'ONE PIECE' => ['ONE PIECE', 'ワンピース'],
        'るろうに剣心' => ['るろうに剣心'],
        '踊る大捜査線' => ['踊る大捜査線'],
        '海猿' => ['海猿'],
        '相棒' => ['相棒'],
        'ガリレオ' => ['ガリレオ'],
        'HERO' => ['HERO'],
        'ALWAYS 三丁目の夕日' => ['ALWAYS', '三丁目の夕日'],
        'キングダム' => ['キングダム'],
        '東京リベンジャーズ' => ['東京リベンジャーズ', '東京卍リベンジャーズ'],
        '「マスカレード」シリーズ' => ['マスカレード'],
        'かぐや様は告らせたい〜天才たちの恋愛頭脳戦〜' => ['かぐや様は告らせたい'],
        'スマホを落としただけなのに' => ['スマホを落としただけなのに'],
        '事故物件 恐い間取り' => ['事故物件'],
        '映画 すみっコぐらし' => ['すみっコぐらし'],
        'ラブライブ!シリーズ' => ['ラブライブ'],
        'うたの☆プリンスさまっ♪' => ['うたの☆プリンスさまっ', 'うたプリ'],
        'ソードアート・オンライン' => ['ソードアート・オンライン'],
        'シティーハンター' => ['シティーハンター'],
        '沈黙の艦隊' => ['沈黙の艦隊'],
        'ゴールデンカムイ' => ['ゴールデンカムイ'],
        'ゲゲゲの鬼太郎（実写）' => ['ゲゲゲの鬼太郎'],
        '加賀恭一郎シリーズ' => ['祈りの幕が下りる時', '麒麟の翼', '真夏の方程式'],
        'ロバート・ラングドン' => ['ダ・ヴィンチ・コード', '天使と悪魔', 'インフェルノ'],
        'ナルニア国物語' => ['ナルニア'],
        'アバター' => ['アバター'],
        'トップガン' => ['トップガン'],
        'MEG ザ・モンスター' => ['MEG'],
        'テルマエ・ロマエ' => ['テルマエ・ロマエ'],
        '翔んで埼玉' => ['翔んで埼玉'],
        'カイジ' => ['カイジ'],
        'マトリックス' => ['マトリックス'],
        '怪盗グルー' => ['怪盗グルー', 'ミニオン'],
        'アリス・イン・ワンダーランド' => ['アリス・イン・ワンダーランド'],
        'TOKYO MER〜走る緊急救命室〜' => ['TOKYO MER'],
        'ドラえもん' => ['ドラえもん'],
    ];

    /**
     * @param  array<string, mixed>  $series
     * @return list<string>
     */
    public static function aliases(array $series): array
    {
        $name = trim((string) ($series['name'] ?? ''));
        $aliases = [];
        if ($name !== '') {
            $aliases[] = $name;
            $stripped = trim((string) preg_replace('/(シリーズ|（[^）]*）|\([^)]*\))$/u', '', $name));
            if ($stripped !== '' && $stripped !== $name) {
                $aliases[] = $stripped;
            }
        }

        foreach (self::EXTRA_ALIASES[$name] ?? [] as $alias) {
            $aliases[] = $alias;
        }

        $unique = [];
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $unique[$alias] = $alias;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $film
     */
    public static function belongs(array $series, array $film): bool
    {
        return self::score($series, $film) > 0;
    }

    /**
     * より長いエイリアス一致ほど高い。0 は非所属。
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $film
     */
    public static function score(array $series, array $film): int
    {
        $title = SeriesFilmMatcher::compact((string) ($film['title'] ?? ''));
        $short = SeriesFilmMatcher::compact((string) ($film['shortTitle'] ?? ''));
        $best = 0;
        foreach (self::aliases($series) as $alias) {
            $needle = SeriesFilmMatcher::compact($alias);
            if ($needle === '' || mb_strlen($needle) < 2) {
                continue;
            }
            foreach ([$title, $short] as $haystack) {
                if ($haystack === '') {
                    continue;
                }
                if ($haystack === $needle || str_starts_with($haystack, $needle) || str_contains($haystack, $needle)) {
                    $best = max($best, mb_strlen($needle));
                }
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @param  array<string, mixed>  $film
     * @return array{series: ?array<string, mixed>, ambiguous: list<string>}
     */
    public static function assign(array $series, array $film): array
    {
        $best = [];
        $bestScore = 0;
        foreach ($series as $row) {
            $score = self::score($row, $film);
            if ($score === 0) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$row];
            } elseif ($score === $bestScore) {
                $best[] = $row;
            }
        }

        if ($best === []) {
            return ['series' => null, 'ambiguous' => []];
        }
        if (count($best) > 1) {
            return [
                'series' => null,
                'ambiguous' => array_values(array_map(fn (array $row) => (string) ($row['name'] ?? ''), $best)),
            ];
        }

        return ['series' => $best[0], 'ambiguous' => []];
    }
}
