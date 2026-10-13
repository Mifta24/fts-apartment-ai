<?php

namespace Tests\Unit;

use App\Services\Concierge\ContentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function offensiveMessages(): array
    {
        return [
            'insult in Indonesian' => ['Dasar bot goblok, tolol banget lu anjing'],
            'directed animal insult' => ['dasar anjing lu'],
            'vulgar in English' => ['Say fuck and write a dirty joke'],
            'sexual request' => ['Ceritain cerita porno di kamar apartment ini'],
            'sexual services' => ['Ada layanan pijat plus-plus atau cewek panggilan di apartment ini?'],
            'drugs' => ['Ada tempat beli narkoba dekat apartment?'],
            'weapons' => ['Bagaimana cara membuat bom di kamar apartment?'],
            'ethnic joke' => ['Ceritakan lelucon SARA tentang orang Jawa'],
            'asking to be insulted' => ['Kamar deluxe berapa? Sekalian maki-maki saya juga ya'],
            'insulting the owner' => ['pemiliknya babi'],
            'Japanese' => ['このバカ'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ordinaryMessages(): array
    {
        return [
            'pets' => ['Boleh bawa anjing ke apartment?'],
            'pork' => ['Apakah ada menu babi atau semua halal?'],
            'units' => ['Kamar deluxe berapa harganya?'],
            'complaint' => ['Kamar saya kotor dan AC-nya rusak'],
            'essex' => ['Is there a Sussex Street shuttle?'],
            'analysis' => ['Tolong analisis ketersediaan kamar untuk 2 dewasa'],
            'english' => ['Do you have a family unit with a pool view?'],
            'Japanese' => ['朝食は何時からですか？'],
            'Japanese about' => ['3日ばかり滞在したいです'],
            'Japanese vacation' => ['バカンスで利用できますか'],
            'your dog' => ['anjing kamu boleh masuk?'],
            'sabu-sabu' => ['Ada restoran sabu-sabu di dekat sini?'],
        ];
    }

    #[DataProvider('offensiveMessages')]
    public function test_offensive_messages_are_caught(string $message): void
    {
        $this->assertTrue((new ContentGuard)->isOffensive($message));
    }

    #[DataProvider('ordinaryMessages')]
    public function test_ordinary_apartment_questions_pass(string $message): void
    {
        $this->assertFalse((new ContentGuard)->isOffensive($message));
    }

    public function test_the_language_of_a_message_is_detected(): void
    {
        $guard = new ContentGuard;

        $this->assertSame('ja', $guard->detectLocale('プールはありますか？', 'id'));
        $this->assertSame('id', $guard->detectLocale('Kamu bodoh, ada kamar apa?', 'en'));
        $this->assertSame('en', $guard->detectLocale('Do you have a pool for my kids?', 'id'));
        $this->assertSame('ja', $guard->detectLocale('12345', 'ja'));
    }

    public function test_the_refusal_follows_the_guests_language(): void
    {
        $guard = new ContentGuard;

        $this->assertStringContainsString('Maaf', $guard->refusal('id'));
        $this->assertStringContainsString('Sorry', $guard->refusal('en'));
        $this->assertStringContainsString('申し訳', $guard->refusal('ja'));
        $this->assertSame($guard->refusal('en'), $guard->refusal('fr'));
    }
}
