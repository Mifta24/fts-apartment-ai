<?php

namespace App\Services\Reservation;

use App\Models\Apartment;
use App\Models\Booking;
use App\Notifications\GuestBookingSummary;

/**
 * Builds the WhatsApp / phone / email hand-over links that carry a
 * reservation summary (or a plain "talk to staff" request) to the apartment team.
 */
class ReservationHandover
{
    /**
     * @return array{whatsapp_url: ?string, phone_url: ?string, email_url: ?string}
     */
    public function forBooking(Apartment $apartment, Booking $booking, string $locale): array
    {
        $message = $this->reservationMessage($booking, $locale);

        return $this->links($apartment, $message, $this->subject('reservation', $locale).' '.$booking->reference);
    }

    /**
     * @return array{whatsapp_url: ?string, phone_url: ?string, email_url: ?string}
     */
    public function forStaff(Apartment $apartment, string $locale): array
    {
        return $this->links($apartment, $this->subject('staff_message', $locale), $this->subject('staff', $locale));
    }

    /**
     * @return array{whatsapp_url: ?string, phone_url: ?string, email_url: ?string}
     */
    private function links(Apartment $apartment, string $message, string $subject): array
    {
        $whatsapp = preg_replace('/\D+/', '', (string) $apartment->whatsapp);
        $phone = preg_replace('/[^\d+]/', '', (string) $apartment->phone);

        return [
            'whatsapp_url' => $whatsapp !== '' ? 'https://wa.me/'.$whatsapp.'?text='.rawurlencode($message) : null,
            'phone_url' => $phone !== '' ? 'tel:'.$phone : null,
            'email_url' => filled($apartment->email) ? 'mailto:'.$apartment->email.'?subject='.rawurlencode($subject).'&body='.rawurlencode($message) : null,
        ];
    }

    private function reservationMessage(Booking $booking, string $locale): string
    {
        $booking->loadMissing('unitType');

        $labels = match ($locale) {
            'en' => ['Hello, I would like to request a stay at the apartment.', 'Name', 'Move-in', 'Move-out', 'Residents', 'Units', 'Unit type', 'Special request', 'Reference', 'Rental'],
            'ja' => ['こんにちは。アパートメントの滞在をリクエストしたいです。', 'お名前', '入居日', '退去日', '人数', '戸数', 'お部屋タイプ', 'ご要望', '予約番号', '契約形態'],
            default => ['Halo, saya ingin mengajukan permintaan sewa unit apartemen.', 'Nama', 'Check-in', 'Check-out', 'Penghuni', 'Jumlah unit', 'Tipe unit', 'Permintaan khusus', 'Referensi', 'Jenis sewa'],
        };

        $guests = $booking->adults + $booking->children;

        return implode("\n", array_filter([
            $labels[0],
            '',
            "{$labels[1]}: {$booking->guest_name}",
            $booking->isLease() ? "{$labels[9]}: ".GuestBookingSummary::leaseLabel($booking, $locale) : null,
            "{$labels[2]}: ".$booking->check_in->locale($locale)->translatedFormat('j F Y'),
            "{$labels[3]}: ".$booking->check_out->locale($locale)->translatedFormat('j F Y'),
            "{$labels[4]}: {$guests}",
            "{$labels[5]}: {$booking->unit_count}",
            "{$labels[6]}: ".$booking->unitType->translatedName($locale),
            "{$labels[7]}: ".($booking->notes ?: '-'),
            '',
            "{$labels[8]}: {$booking->reference}",
        ], fn ($line) => $line !== null));
    }

    private function subject(string $key, string $locale): string
    {
        return [
            'en' => ['reservation' => 'Stay request', 'staff' => 'Question for the apartment team', 'staff_message' => 'Hello, I would like to speak with the apartment team.'],
            'ja' => ['reservation' => '滞在リクエスト', 'staff' => 'スタッフへのご相談', 'staff_message' => 'こんにちは。アパートメントのスタッフとお話ししたいです。'],
            'id' => ['reservation' => 'Permintaan sewa unit', 'staff' => 'Pertanyaan untuk tim apartemen', 'staff_message' => 'Halo, saya ingin bicara dengan tim apartemen.'],
        ][$locale][$key];
    }
}
