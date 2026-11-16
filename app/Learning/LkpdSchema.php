<?php

namespace App\Learning;

class LkpdSchema
{
    public static function fields(int $topic): array
    {
        static $schemas;
        $schemas ??= json_decode(file_get_contents(database_path('data/lkpd-update.json')), true, flags: JSON_THROW_ON_ERROR);

        return $schemas[$topic]['fields'];
    }

    public static function legacyFields(int $topic): array
    {
        return match ($topic) {
            0 => [
                ['key' => 'facts', 'label' => 'Apa yang kamu temukan pada peta?', 'hint' => 'Catat 4 pulau, 2 laut/selat, 2 benua, 2 samudra, arah mata angin, dan sumber petamu.', 'phase' => 1],
                ['key' => 'benefits', 'label' => 'Apa manfaat kapal bagi warga?', 'hint' => 'Tuliskan sedikitnya 2 manfaat.', 'phase' => 2],
                ['key' => 'risks', 'label' => 'Apa risiko yang perlu kita cegah?', 'hint' => 'Tuliskan sedikitnya 2 risiko bagi laut dan kehidupan.', 'phase' => 2],
                ['key' => 'solutions', 'label' => 'Bagaimana kita membantu menjaga laut?', 'hint' => 'Bandingkan 2 langkah pencegahan yang aman.', 'phase' => 4],
                ['key' => 'decision', 'label' => 'Apa keputusan kelompokmu?', 'hint' => 'Jelaskan alasan dan nilai yang ingin kalian wujudkan.', 'phase' => 3],
            ],
            1 => [
                ['key' => 'facts', 'label' => 'Apa hubungan alam, iklim, dan pekerjaan warga?', 'hint' => 'Pilih satu daerah, tuliskan fakta dan sumbernya.', 'phase' => 1],
                ['key' => 'benefits', 'label' => 'Apa manfaat pemanfaatan lahan?', 'hint' => 'Pikirkan manfaat bagi kehidupan warga.', 'phase' => 2],
                ['key' => 'risks', 'label' => 'Apa risikonya bagi hutan?', 'hint' => 'Pikirkan air, habitat, dan makhluk hidup.', 'phase' => 2],
                ['key' => 'affected', 'label' => 'Siapa yang akan terdampak?', 'hint' => 'Perhatikan warga dan kehidupan alam.', 'phase' => 3],
                ['key' => 'decision', 'label' => 'Pilihan apa yang paling bertanggung jawab?', 'hint' => 'Jelaskan alasan, solusi, dan satu kebiasaan peduli yang ingin dilakukan.', 'phase' => 4],
            ],
            2 => [
                ['key' => 'comparison', 'label' => 'Apa perbedaan kehidupan maritim dan agraris?', 'hint' => 'Bandingkan ciri, pekerjaan, hasil bumi dan budaya.', 'phase' => 1],
                ['key' => 'sources', 'label' => 'Dari mana informasi kelompokmu?', 'hint' => 'Gunakan dua bacaan atau wawancara dengan pendamping; bedakan fakta dan opini.', 'phase' => 1],
                ['key' => 'arguments', 'label' => 'Bagaimana wisata atau produksi memengaruhi warga dan alam?', 'hint' => 'Tuliskan dua argumen dengan bukti dan pihak terdampak.', 'phase' => 2],
                ['key' => 'decision', 'label' => 'Solusi adil apa yang kelompokmu pilih?', 'hint' => 'Jelaskan cara menghargai petani, nelayan, dan lingkungan.', 'phase' => 4],
            ],
            default => [
                ['key' => 'facts', 'label' => 'Apa tiga fakta yang mendukung aksi kalian?', 'hint' => 'Catat sumber agar teman dapat memeriksanya.', 'phase' => 1],
                ['key' => 'problem', 'label' => 'Masalah apa yang ingin kalian bantu atasi?', 'hint' => 'Jelaskan lokasi dan siapa yang terdampak.', 'phase' => 2],
                ['key' => 'alternatives', 'label' => 'Apa dua pilihan aksi yang mungkin dilakukan?', 'hint' => 'Bandingkan keamanan, kemudahan, biaya, manfaat, dan bukti hasilnya.', 'phase' => 2],
                ['key' => 'decision', 'label' => 'Aksi mana yang kalian pilih dan mengapa?', 'hint' => 'Hubungkan dengan kepedulian, tanggung jawab, dan gotong royong.', 'phase' => 3],
                ['key' => 'plan', 'label' => 'Bagaimana rencana aksi dan karya kalian?', 'hint' => 'Tuliskan jadwal, tugas, alat aman, indikator, cara mencatat bukti, dan ajakan pada poster.', 'phase' => 4],
            ],
        };
    }

    public static function guidance(): array
    {
        return json_decode(file_get_contents(database_path('data/rubrics.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function rubrics(): array
    {
        return [
            'product' => ['accuracy' => 'Akurasi materi', 'analysis' => 'Analisis masalah dan solusi', 'creativity' => 'Kreativitas dan kerapian', 'message' => 'Pesan cinta lingkungan/tanah air'],
            'presentation' => ['mastery' => 'Penguasaan materi', 'communication' => 'Komunikasi dan kerja sama'],
        ];
    }
}
