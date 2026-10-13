<?php

namespace App\Notifications;

use App\Models\Booking;

/**
 * The few lines every guest email repeats about the stay, in the guest's language.
 */
class GuestBookingSummary
{
    /**
     * @return list<string>
     */
    public static function lines(Booking $booking, string $locale): array
    {
        $booking->loadMissing(['apartment', 'unitType']);

        $labels = match ($locale) {
            'ja' => ['予約番号', 'お部屋', '入居日', '退去日', '人数', '合計', '契約形態', '敷金（返還）'],
            'id' => ['Referensi', 'Unit', 'Check-in', 'Check-out', 'Penghuni', 'Total', 'Jenis sewa', 'Deposit (dikembalikan)'],
            default => ['Reference', 'Unit', 'Move-in', 'Move-out', 'Residents', 'Total', 'Rental', 'Security deposit (refundable)'],
        };

        $money = fn ($value) => "{$booking->apartment->currency} ".number_format((float) $value, 0, ',', '.');

        $dateFormat = $locale === 'ja' ? 'Y年n月j日' : 'j F Y';
        $format = fn ($date) => $date->locale($locale)->translatedFormat($dateFormat);

        return array_values(array_filter([
            "{$labels[0]}: {$booking->reference}",
            "{$labels[1]}: {$booking->unit_count} × {$booking->unitType->translatedName($locale)}",
            $booking->isLease() ? "{$labels[6]}: ".self::leaseLabel($booking, $locale) : null,
            "{$labels[2]}: {$format($booking->check_in)}",
            "{$labels[3]}: {$format($booking->check_out)}",
            "{$labels[4]}: ".($booking->adults + $booking->children),
            "{$labels[5]}: {$money($booking->total_price)}",
            $booking->deposit_total ? "{$labels[7]}: {$money($booking->deposit_total)}" : null,
        ]));
    }

    /**
     * "Monthly rental, 6 months" or "Yearly rental, 2 years" in the guest's language.
     */
    public static function leaseLabel(Booking $booking, string $locale): string
    {
        $yearly = $booking->rental_type === Booking::RENTAL_YEARLY;
        $count = $yearly ? intdiv((int) $booking->lease_months, 12) : (int) $booking->lease_months;

        return match ($locale) {
            'ja' => ($yearly ? '年契約' : '月契約').'、'.$count.($yearly ? '年' : 'か月'),
            'id' => ($yearly ? 'Sewa tahunan' : 'Sewa bulanan').", {$count} ".($yearly ? 'tahun' : 'bulan'),
            default => ($yearly ? 'Yearly rental' : 'Monthly rental').", {$count} ".($yearly ? ($count === 1 ? 'year' : 'years') : ($count === 1 ? 'month' : 'months')),
        };
    }
}
