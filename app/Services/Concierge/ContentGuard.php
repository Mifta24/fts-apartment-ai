<?php

namespace App\Services\Concierge;

/**
 * Deterministic backstop for the concierge's prompt rules. The local model is
 * an uncensored one, so asking it politely to stay clean is not enough: rude,
 * sexual or illegal messages are answered with a fixed reply without ever
 * reaching the model, and a model reply that still contains such words is
 * replaced. Patterns are deliberately narrow so ordinary apartment questions
 * ("are dogs allowed?", "is there a pork-free menu?") are never blocked.
 */
class ContentGuard
{
    /**
     * Latin-script patterns, matched case-insensitively on whole words.
     *
     * @var list<string>
     */
    private const LATIN_PATTERNS = [
        // Indonesian insults and vulgarity
        '(?:goblok|tolol|bangsat|brengsek|kampret|bajingan|jancok|jancuk|kontol|memek|ngentot|entot|ngewe|pepek|itil|peler|lonte|jablay|pelacur|perek|sialan)',
        '(?:dasar|lu|lo|loe|elu|kamu|kau|dasar)\s+(?:anjing|anjir|asu|babi|monyet|bodoh|bego|idiot|sampah)',
        '(?:anjing|asu|babi|monyet)\s+(?:lu|lo|loe|elu|banget)',
        '(?:maki|caci|hina)(?:-|\s)?(?:maki|caci|hina)?\s*(?:saya|aku|gue|gw)',
        '(?:pemilik|owner)\w*\s+(?:apartment\s+)?(?:ini\s+)?(?:babi|anjing|bangsat)',
        // Indonesian sexual
        '(?:porno|bokep|mesum|cabul|sange|colmek|coli|onani|bugil|telanjang|ml\s+yuk|ngeseks|seks|sex|sexy|seksi)',
        '(?:cewek|wanita|perempuan|cowok|pria|lelaki)\s+(?:panggilan|bayaran|sewaan)',
        '(?:plus[\s-]?plus|pijat\s+(?:plus|mesum|erotis)|open\s+bo|bo\s+an)',
        // Indonesian illegal
        '(?:narkoba|narkotika|(?<!sabu-)sabu(?!-sabu)|kokain|heroin|ekstasi|inex|putau|beli\s+ganja|jual\s+ganja|senjata\s+api|jual\s+senjata)',
        '(?:membuat|bikin|cara\s+buat|cara\s+membuat|rakit|merakit)\s+(?:bom|senjata|racun|peledak)',
        '(?:lelucon|guyon|candaan|humor|joke)\s+(?:sara|rasis|rasial)',
        // English
        '(?:fuck\w*|shit\w*|bitch\w*|asshole|bastard|dickhead|cunt|whore|slut|pussy|cock|dick|nigg\w+|motherfucker|porn\w*|nude\w*|erotic\w*|blowjob|handjob|horny|escort|hooker|prostitute\w*)',
        '(?:cocaine|heroin|meth|methamphetamine|marijuana\s+dealer|buy\s+(?:drugs|weed|coke))',
        '(?:make|build|making|how\s+to\s+make)\s+(?:a\s+)?(?:bomb|explosive|poison)',
        '(?:you|u)\s+(?:are|r)\s+(?:so\s+)?(?:stupid|idiot|dumb|trash|useless)',
    ];

    /**
     * Japanese has no word boundaries, so these are substring patterns. The
     * lookaheads keep everyday words (バカンス "vacation", ばかり "about") apart
     * from the insults they begin with.
     *
     * @var list<string>
     */
    private const JAPANESE_TERMS = [
        'くそ', 'クソ', 'バカ(?!ンス)', 'ばか(?!り)', '馬鹿', '死ね', 'ちんこ', 'チンコ', 'まんこ', 'マンコ',
        'エロ', 'セックス', 'ポルノ', 'アダルト', '風俗', 'デリヘル', '売春', '麻薬', '覚醒剤', '爆弾',
    ];

    private const REFUSALS = [
        'id' => 'Maaf, saya tidak bisa membantu dengan hal itu. Saya siap membantu soal unit, fasilitas gedung, permintaan sewa, atau menghubungkan Anda dengan tim apartemen.',
        'en' => 'Sorry, I cannot help with that. I am happy to help with units, building facilities, rental applications, or connecting you with the apartment team.',
        'ja' => '申し訳ございませんが、そのご依頼にはお応えできません。お部屋、共用施設、滞在のご依頼、スタッフへのご連絡でしたらお手伝いいたします。',
    ];

    /**
     * Whether the text contains rude, sexual or illegal wording.
     */
    public function isOffensive(string $text): bool
    {
        $normalized = mb_strtolower($text);

        foreach (self::LATIN_PATTERNS as $pattern) {
            if (preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu', $normalized) === 1) {
                return true;
            }
        }

        foreach (self::JAPANESE_TERMS as $term) {
            if (preg_match('/'.$term.'/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private const INDONESIAN_WORDS = ['yang', 'dan', 'apa', 'ada', 'saya', 'aku', 'kamu', 'anda', 'bisa', 'untuk', 'tidak', 'ini', 'itu', 'dong', 'nggak', 'gak', 'berapa', 'mau', 'dengan', 'di', 'ke', 'dari', 'kamar', 'lu', 'gue', 'sewa', 'bulanan', 'tahunan', 'bulan', 'tahun', 'harga', 'tipe', 'adalah', 'juga', 'atau', 'pada', 'akan', 'sudah', 'jika', 'silakan', 'kami', 'kita', 'dapat', 'memiliki', 'tersedia', 'hubungi', 'berikut', 'semua', 'setiap', 'sebesar', 'sekitar', 'bagaimana', 'bisakah', 'tolong', 'terima', 'kasih'];

    private const ENGLISH_WORDS = ['the', 'and', 'what', 'is', 'are', 'you', 'your', 'can', 'do', 'does', 'i', 'my', 'me', 'have', 'with', 'for', 'this', 'that', 'how', 'much', 'please', 'tell', 'say', 'write', 'a', 'an', 'to', 'of', 'in', 'on', 'at', 'we', 'our', 'it', 'if', 'will', 'would', 'monthly', 'rent', 'rental', 'available', 'from', 'month', 'year', 'or', 'be', 'by', 'there', 'here', 'about', 'which', 'when', 'where', 'yes', 'also', 'includes', 'included'];

    /**
     * Best guess of the language a message is written in, so a fixed reply can
     * match the guest rather than the page. Falls back when it is unclear.
     */
    public function detectLocale(string $text, string $fallback): string
    {
        if (preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $text) === 1) {
            return 'ja';
        }

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $indonesian = count(array_intersect($words, self::INDONESIAN_WORDS));
        $english = count(array_intersect($words, self::ENGLISH_WORDS));

        return match (true) {
            $indonesian > $english => 'id',
            $english > $indonesian => 'en',
            default => $fallback,
        };
    }

    /**
     * The fixed reply for a refused message, in the given language.
     */
    public function refusal(string $locale): string
    {
        return self::REFUSALS[$locale] ?? self::REFUSALS['en'];
    }
}
