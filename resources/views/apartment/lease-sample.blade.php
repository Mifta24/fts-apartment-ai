@php
    $deposit = $apartment->security_deposit_months;
    $copy = match ($locale) {
        'en' => [
            'title' => 'Sample residential lease agreement',
            'banner' => 'SAMPLE ONLY — not an offer or a contract. The leasing team prepares the final agreement after your application is approved; terms and amounts may differ.',
            'parties' => 'Parties',
            'lines' => ['Landlord: :apartment, :address.', 'Tenant: [full name], [ID card / passport number], [address].'],
            'clauses' => [
                'Unit and term' => 'The landlord leases unit [unit number] ([unit type], floor [floor]) to the tenant for [number] months from [move-in date] to [end date]. The unit is handed over furnished, with an inventory list signed by both parties.',
                'Rent and payment' => 'Rent is IDR [amount] per month (or IDR [amount] per year for a yearly lease), paid in advance as agreed with the leasing team. Rent for the first period and the deposit are due before the lease is signed.',
                'Security deposit' => "The tenant pays a refundable security deposit of {$deposit} months of rent. It is returned within 7 working days after the lease ends and the unit is inspected, minus unpaid bills and damage beyond normal wear.",
                'Utilities and services' => 'Rent includes the building service charge, water and Wi-Fi. Electricity is billed monthly by meter reading. Optional services (unit cleaning, laundry, extra parking) are charged separately.',
                'Use of the unit' => 'The unit is for residential use by the registered residents only. The tenant follows the building house rules (quiet hours, smoking, visitors, pets) and may not sublet without written consent.',
                'Maintenance' => 'The landlord maintains the building, its systems and the furniture supplied. The tenant looks after the unit, reports faults promptly and pays for damage they cause.',
                'Renewal' => 'The tenant tells the landlord at least 60 days before the end of the term if they wish to renew. Renewal rent follows the rate in force at that time.',
                'Early termination' => 'Either party gives at least 30 days\' written notice. Rent already paid for the rest of the term is not refunded unless the landlord approves a replacement tenant.',
                'Handover and return' => 'At the end of the lease the tenant returns the unit and all access cards in the condition recorded at move-in, normal wear excepted.',
                'Disputes' => 'The parties first try to settle any dispute amicably; failing that, it is brought before the competent court in South Jakarta.',
            ],
            'sign' => 'Landlord' , 'sign2' => 'Tenant', 'back' => 'Back to the application', 'print' => 'Print',
        ],
        'ja' => [
            'title' => '賃貸借契約書（サンプル）',
            'banner' => 'サンプルです。申込や契約ではありません。最終的な契約書は、お申し込みの承認後にリーシングチームが作成します。条件や金額は異なる場合があります。',
            'parties' => '契約当事者',
            'lines' => ['貸主：:apartment（:address）', '借主：[氏名]、[身分証／パスポート番号]、[住所]'],
            'clauses' => [
                '対象のお部屋と契約期間' => '貸主は借主に、[号室]（[タイプ]、[階]階）を[入居日]から[終了日]までの[月数]か月間賃貸します。お部屋は家具付きで引き渡し、双方が署名した備品リストを作成します。',
                '賃料とお支払い' => '賃料は月額[金額]ルピア（年契約は年額[金額]ルピア）で、リーシングチームと合意した方法で前払いします。最初の賃料期間分と敷金は、契約締結前にお支払いいただきます。',
                '敷金' => "借主は賃料{$deposit}か月分の敷金を預けます。契約終了後、お部屋の確認を経て7営業日以内に、未払い分および通常の使用を超える損傷分を差し引いて返還します。",
                '光熱費とサービス' => '賃料には建物のサービスチャージ、水道、Wi-Fiが含まれます。電気代はメーターの使用量に基づき毎月請求します。有料オプション（清掃、ランドリー、追加駐車場）は別途請求します。',
                'お部屋の利用' => '登録された入居者の居住用に限り使用できます。借主は館内ルール（静粛時間、禁煙、来訪者、ペット）を守り、書面による同意なく転貸してはなりません。',
                '維持管理' => '貸主は建物、設備、備え付けの家具を維持管理します。借主はお部屋を適切に使用し、不具合をすみやかに報告し、自己の責めによる損傷を負担します。',
                '契約更新' => '更新を希望する場合、借主は契約終了の60日前までに貸主へ通知します。更新後の賃料はその時点の賃料に準じます。',
                '中途解約' => 'いずれの当事者も30日前までに書面で通知します。残りの期間分として支払済みの賃料は、貸主が後任の入居者を承認した場合を除き返金されません。',
                '明け渡し' => '契約終了時、借主は入居時に記録した状態（通常の使用による損耗を除く）でお部屋とアクセスカードを返却します。',
                '紛争解決' => '紛争はまず当事者間の協議で解決を図り、解決しない場合は南ジャカルタの管轄裁判所に提起します。',
            ],
            'sign' => '貸主', 'sign2' => '借主', 'back' => '申し込みに戻る', 'print' => '印刷',
        ],
        default => [
            'title' => 'Contoh perjanjian sewa hunian',
            'banner' => 'HANYA CONTOH — bukan penawaran maupun kontrak. Perjanjian final disiapkan tim leasing setelah pengajuan Anda disetujui; ketentuan dan nominal dapat berbeda.',
            'parties' => 'Para pihak',
            'lines' => ['Pemilik: :apartment, :address.', 'Penyewa: [nama lengkap], [nomor KTP / paspor], [alamat].'],
            'clauses' => [
                'Unit dan masa sewa' => 'Pemilik menyewakan unit [nomor unit] ([tipe unit], lantai [lantai]) kepada penyewa selama [jumlah] bulan, mulai [tanggal masuk] sampai [tanggal berakhir]. Unit diserahkan dalam keadaan berperabot, dengan daftar inventaris yang ditandatangani kedua pihak.',
                'Sewa dan pembayaran' => 'Sewa sebesar Rp[nominal] per bulan (atau Rp[nominal] per tahun untuk sewa tahunan), dibayar di muka sesuai kesepakatan dengan tim leasing. Sewa periode pertama dan deposit dibayar sebelum perjanjian ditandatangani.',
                'Deposit keamanan' => "Penyewa membayar deposit keamanan yang dapat dikembalikan sebesar {$deposit} bulan sewa. Deposit dikembalikan paling lambat 7 hari kerja setelah masa sewa berakhir dan unit diperiksa, dikurangi tagihan tertunggak dan kerusakan di luar pemakaian wajar.",
                'Listrik dan layanan' => 'Sewa sudah termasuk service charge gedung, air, dan Wi-Fi. Listrik ditagihkan setiap bulan sesuai pemakaian meteran. Layanan opsional (kebersihan unit, laundry, parkir tambahan) dikenakan biaya terpisah.',
                'Penggunaan unit' => 'Unit hanya untuk hunian oleh penghuni yang terdaftar. Penyewa mematuhi aturan gedung (jam tenang, merokok, tamu, hewan peliharaan) dan tidak boleh menyewakan kembali tanpa persetujuan tertulis.',
                'Perawatan' => 'Pemilik merawat gedung, sistemnya, dan perabot yang disediakan. Penyewa menjaga unit, segera melaporkan kerusakan, dan menanggung kerusakan yang ia sebabkan.',
                'Perpanjangan' => 'Penyewa yang ingin memperpanjang memberi tahu pemilik paling lambat 60 hari sebelum masa sewa berakhir. Sewa perpanjangan mengikuti tarif yang berlaku saat itu.',
                'Pemutusan lebih awal' => 'Masing-masing pihak memberi pemberitahuan tertulis minimal 30 hari. Sewa yang sudah dibayar untuk sisa masa sewa tidak dikembalikan, kecuali pemilik menyetujui penyewa pengganti.',
                'Serah terima dan pengembalian' => 'Di akhir masa sewa, penyewa mengembalikan unit dan seluruh kartu akses dalam kondisi seperti saat masuk, di luar pemakaian wajar.',
                'Perselisihan' => 'Para pihak lebih dulu menyelesaikan perselisihan secara musyawarah; bila tidak berhasil, diajukan ke pengadilan yang berwenang di Jakarta Selatan.',
            ],
            'sign' => 'Pemilik', 'sign2' => 'Penyewa', 'back' => 'Kembali ke pengajuan', 'print' => 'Cetak',
        ],
    };
    $address = trim($apartment->address.', '.$apartment->city, ', ');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $copy['title'] }} · {{ $apartment->name }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #fff; --ink: #1c1917; --muted: #57534e; --line: #e7e5e4; --flag: #fef3c7; --flag-ink: #78350f; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1c1917; --ink: #f5f5f4; --muted: #a8a29e; --line: #44403c; --flag: #451a03; --flag-ink: #fde68a; } }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.6 system-ui, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 26px; line-height: 1.2; margin: 0 0 12px; }
        .banner { padding: 12px 14px; border-radius: 6px; background: var(--flag); color: var(--flag-ink); font-size: 14px; }
        h2 { margin: 28px 0 6px; font-size: 14px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        ol { padding-left: 20px; } li { margin: 0 0 14px; } li strong { display: block; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 48px; }
        .signatures div { padding-top: 56px; border-top: 1px solid var(--line); color: var(--muted); font-size: 14px; }
        .actions { display: flex; gap: 16px; margin-top: 32px; font-size: 14px; }
        .actions a, .actions button { color: var(--ink); background: none; border: 0; padding: 0; font: inherit; text-decoration: underline; cursor: pointer; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $copy['title'] }}</h1>
    <p class="banner">{{ $copy['banner'] }}</p>

    <h2>{{ $copy['parties'] }}</h2>
    @foreach ($copy['lines'] as $line)
        <p>{{ str_replace([':apartment', ':address'], [$apartment->name, $address], $line) }}</p>
    @endforeach

    <ol>
        @foreach ($copy['clauses'] as $heading => $text)
            <li><strong>{{ $heading }}</strong>{{ $text }}</li>
        @endforeach
    </ol>

    <div class="signatures"><div>{{ $copy['sign'] }}</div><div>{{ $copy['sign2'] }}</div></div>

    <p class="actions">
        <a href="{{ route('apartment.reservation', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]) }}">{{ $copy['back'] }}</a>
        <button type="button" onclick="window.print()">{{ $copy['print'] }}</button>
    </p>
</main>
</body>
</html>
