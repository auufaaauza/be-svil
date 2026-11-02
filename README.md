# SVIL API — Laravel + MySQL

Laravel hanya melayani JSON API /api/v1 dan berkas privat. Next.js meneruskan /api/* serta /sanctum/* ke backend melalui API_BACKEND_URL. Autentikasi menggunakan sesi Sanctum dan CSRF, tanpa token di localStorage.

## Lokal

    composer install
    php artisan migrate --no-interaction
    php artisan serve --host=127.0.0.1 --port=8000 --no-reload

Jalankan npm run dev di ../fe-svill, lalu buka http://127.0.0.1:3000. Database lokal svil_app dan pengujian svil_app_testing menggunakan pengguna MySQL terpisah. Kredensial ada pada .env/.env.testing yang diabaikan Git.

Admin lokal: username **admin** atau email **admin@findmycareer.my.id**, password **svil123** sesuai permintaan. Data contoh siswa/guru, kelas dan materi sudah dibersihkan. DatabaseSeeder kosong. Seeder contoh sudah dihapus; tidak ada akun maupun jawaban contoh yang dibuat saat instalasi.

## Konten LKPD utama

Empat dokumen LKPD asli tersedia di database/data/lkpd.json, bersumber dari DOCX dalam ZIP Dokumen Pendukung. Setelah admin dibuat, impor dengan:

    php artisan svil:import-lkpd --by=admin@findmycareer.my.id --no-interaction

Perintah mencatat pengimpor dan hash sumber, melewati LKPD yang sudah ada, serta tidak membuat akun/kelas/hasil contoh. GET /learning-modules dan /learning-modules/{id} menyediakan metadata dan teks lengkap. PUT /learning-modules/{id} hanya admin, dengan version dan riwayat. group_works menyimpan module_version dan schema_snapshot agar pertanyaan lama tetap dapat ditelusuri.

## Identitas dan batas akses

Login menerima JSON login (username atau email) dan password. Field email lama tetap diterima untuk kompatibilitas. Username/email dinormalisasi ke huruf kecil.

Registrasi publik hanya membuat **siswa** dengan name, username, email, password, password_confirmation, class_code. Kode kelas 12 karakter diberikan guru/admin. Role dan sekolah dari input tidak dipercaya; sekolah serta keanggotaan diambil dari kelas tujuan. Guru/admin dibuat admin. Password pendaftaran minimal 8 karakter; akun yang dibuat admin minimal 12.

Login, registrasi dan jawaban latihan dibatasi rate limit. Akun nonaktif ditolak. Guru hanya mendapat kelas penugasannya; siswa hanya data pribadi dan kelompok sendiri. Gambar disimpan privat serta diperiksa izin ketika diunduh.

## Fitur dan endpoint

Daftar aktual:

    php artisan route:list --path=api --except-vendor

| Area | Endpoint setelah /api/v1 | Perilaku |
| --- | --- | --- |
| Sesi | POST /auth/login, POST /auth/register, POST /auth/logout, GET /me | Sesi cookie, role server |
| Kelas/kelompok | GET /classrooms, GET /groups | Scope pengguna; siswa hanya kelompok sendiri |
| Anggota/kelompok | GET /classrooms/{id}/members, POST /groups | Guru kelas/admin; validasi anggota satu kelas dan satu kelompok |
| Materi | GET/POST /materials, GET/PUT /materials/{id} | Draf/terbit dan optimistic version |
| Gambar | GET /materials/{id}/image | Berkas privat |
| Latihan | POST /materials/{id}/attempts, GET /attempts | Nilai server, request_id UUID, snapshot soal, siswa hanya sendiri |
| Tinjauan latihan | PUT /attempts/{id}/review | Guru kelas/admin, review version dan riwayat; kunci otomatis tidak dapat ditimpa |
| Catatan | GET/PUT /notes, PUT /notes/{id}/review | Catatan individu per kelas/topik/fase dan umpan balik |
| LKPD | GET/PUT /groups/{id}/work/{topic} | Topic 0–3; draf/kirim, anggota saja untuk menulis |
| Tinjauan LKPD | PUT /group-work/{id}/review | Perbaikan/ditinjau, rubrik produk/presentasi terpisah pada LKPD 4 |
| Aksi | GET/PUT /groups/{id}/action | Rencana kelompok berversi |
| Jurnal | POST /actions/{id}/journals | Request UUID, tanggal aktual, teks/checklist, snapshot rencana |
| Verifikasi | PUT /actions/{id}/verify | Guru kelas; harus ada jurnal, pemeriksaan versi |
| Observasi | GET/POST /observations | Guru mencatat 4 skor 1–4, bukti/feedback, dua kesempatan per topik; siswa membaca sendiri |
| Laporan | GET /reports/summary, /reports/notes.csv, /reports/learning.csv | Guru/admin, scope kelas; formula spreadsheet dinetralkan |
| Referensi admin | GET/POST /schools, POST /classrooms, POST /classrooms/{id}/members, GET/POST /users, PATCH /users/{id}/active | Admin |

List memakai pagination. Validasi 422; sesi belum login 401; akses 403; konflik versi 409; sesi/CSRF 419; rate limit 429.

Ambil /sanctum/csrf-cookie sebelum login; kirim X-XSRF-TOKEN dari cookie yang telah di-URL-decode untuk permintaan tulis. Baca ulang cookie setelah pergantian sesi.

Materi menerima JSON atau multipart. Gambar PNG/JPEG/WebP maksimum 1 MB dan 6000×6000; deskripsi wajib. Update multipart menggunakan POST + _method=PUT. Question memiliki type, prompt, options, correctIndex, modelAnswer, explanation, visualTopic opsional 0–3. Kunci/pedoman tidak dikirim ke siswa. Jawaban terbuka ditinjau guru, termasuk opsi feedback tanpa benar/salah.

Kirim version untuk perubahan materi/LKPD/rencana/observasi, review version untuk tinjauan latihan. UUID pengiriman jawaban/jurnal dipertahankan saat retry jaringan. Konflik tidak diulang otomatis; ambil versi terbaru agar perubahan tidak menimpa pekerjaan pihak lain.

## Materi, kuis dan diskusi LMS

Materi dan kuis menggunakan kind=material atau quiz, subject, kelas dan status terpisah. Kuis wajib memiliki question. GET /materials mendukung filter kind, classroom_id, status dan search. Resource menyertakan uploadedBy, updatedBy, canEdit, createdAt dan updatedAt; pengunggah ditetapkan dari sesi dan tidak dapat dipalsukan dari input.

Lampiran PDF/PPTX maksimum 10 MB memakai multipart attachment dan remove_attachment. Download GET /materials/{id}/attachment memeriksa sesi/scope dan memaksa unduhan privat. Atur upload_max_filesize sekurangnya 10M dan post_max_size sekurangnya 12M pada hosting.

Diskusi: GET/POST /discussions, GET/PUT /discussions/{id}, GET/POST /discussions/{id}/replies dan PATCH /discussion-replies/{id}/moderate. Guru/admin membuka draf/open/closed; kelas tetap, update berversi. Semua anggota kelas dapat menanggapi diskusi open. request_id UUID mencegah kiriman ganda. Tanggapan tersembunyi tidak dikirim ke siswa; pendamping dapat memoderasi dan siswa memakai nama kode pada percakapan teman.

## Laporan dan kamus ringkas CSV

notes.csv: satu baris catatan individu, P-{id}, kelas, pertemuan/fase 1-based, isi, feedback, waktu.

learning.csv: jenis_data, rekaman_id, peserta P-{id}, kelompok K-{id}, kelas_id, pertemuan 1-based, versi, status, isi_json, diperbarui. Kolom isi_json menyimpan struktur sesuai jenis:

- MATERI / KUIS / DISKUSI / TANGGAPAN_DISKUSI: isi kegiatan dan metadata pembuat; status moderasi tercatat.
- LKPD / LKPD_RIWAYAT: jawaban, status, feedback, rubrik dan anggota pada versi tersebut.
- RENCANA_AKSI / JURNAL_AKSI: rencana, indikator, pembagian tugas, cara pembuktian, jurnal aktual dan snapshot rencana.
- LATIHAN / TINJAUAN_LATIHAN_RIWAYAT: snapshot soal, jawaban, hasil otomatis/manual dan feedback berversi.
- OBSERVASI / OBSERVASI_RIWAYAT: kesempatan, tanggal, empat skor, bukti perilaku dan feedback.

Rubrik produk maksimum 16, presentasi 8, observasi 16; masing-masing dinormalisasi sendiri ke 100. Tidak digabung menjadi skor karakter/efektivitas. CSV memakai kode identitas; teks bebas dapat mengandung nama yang diketik pengguna sehingga perlu review sebelum penyerahan. Ekspor ini operasional dan belum merupakan dataset penelitian final atau snapshot ekspor yang dibekukan.

## Pengujian

.env.testing wajib mengarah ke MySQL **svil_app_testing**; Tests/TestCase menolak database lain sebelum migrasi pengujian.

    php artisan test --compact
    php vendor/bin/pint app routes database tests config bootstrap/app.php --format agent

Pengujian fitur menggunakan MySQL, termasuk scope lintas siswa/kelompok/kelas, registrasi, sesi, upload privat, kunci, idempotensi, konflik revisi, rubrik, verifikasi bukti, observasi dan laporan. Tidak ada data pengujian dimasukkan ke database aplikasi.

Verifikasi terakhir: 72 tes / 708 assertion berhasil. Backup aktual SQL dan storage privat juga telah diverifikasi dan SQL berhasil dipulihkan ke database pengujian terpisah.

## Shared hosting cPanel + Vercel

Target sementara backend: **https://api2.findmycareer.my.id**. Frontend memakai URL proyek Vercel yang sebenarnya. Template: .env.production.example dan ../fe-svill/.env.production.example.

**Versi:** composer.lock saat ini memuat Symfony yang membutuhkan PHP >=8.4.1. Pilih PHP 8.4.1+ di cPanel untuk web dan Composer/CLI. Jika paket hosting hanya menyediakan versi lebih rendah, dependency lock perlu diselaraskan dan diuji terpisah; jangan gunakan --ignore-platform-reqs.

1. Buat database MySQL beserta pengguna melalui cPanel, berikan izin untuk database aplikasi. Nama biasanya memakai awalan akun cPanel; isi persis pada environment.
2. Upload backend di luar direktori publik. Arahkan document root domain/subdomain ke folder **be-svill/public**. Jangan mengekspos .env, vendor atau storage privat. Jika panel tidak mengizinkan document root khusus, tempatkan hanya isi public pada public_html dan sesuaikan dua path ../vendor/autoload.php serta ../bootstrap/app.php di index.php ke folder backend yang sebenarnya.
3. Gunakan Terminal/SSH untuk composer install --no-dev --optimize-autoloader. Jika tidak tersedia, siapkan vendor di lingkungan PHP yang sesuai dan upload sebagai bagian paket rilis. Migration serta pembuatan admin tetap memerlukan akses CLI dari provider; jangan membuat endpoint migration publik.
4. Buat .env dari template. Isi APP_URL, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS (host frontend tanpa https), DB_*, APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true. SESSION_DOMAIN=null dipertahankan karena browser menggunakan proxy frontend.
5. File lokal .env.production sudah disiapkan dengan APP_KEY baru dan tidak masuk paket/Git. Isi kredensial hosting lalu unggah sebagai .env secara privat. Jika membuat environment baru dari template, buat key satu kali melalui php artisan key:generate. Pertahankan key production pada rilis dan migrasi berikutnya.
6. Jalankan php artisan migrate --force, php artisan config:cache, php artisan route:cache. Pastikan storage dan bootstrap/cache dapat ditulis oleh PHP tanpa permission 777.
7. Buat admin pertama melalui perintah interaktif php artisan svil:create-admin admin@findmycareer.my.id --name=Administrator. Username otomatis admin bila belum dipakai; password diminta tersembunyi. Akun lokal tidak otomatis ada di hosting. Lanjutkan dengan perintah impor LKPD di atas agar konten asli tersedia.
8. Pada Vercel pilih Root Directory **fe-svill**, framework Next.js, lalu isi **API_BACKEND_URL=https://api2.findmycareer.my.id** pada environment production sebelum build. Jangan memakai prefix NEXT_PUBLIC_ dan jangan menambahkan /api/v1 pada URL backend.
9. Deploy frontend. Isi URL Vercel final ke FRONTEND_URL dan host-nya ke SANCTUM_STATEFUL_DOMAINS backend, lalu jalankan ulang config:cache. Gunakan HTTPS pada dua layanan.
10. Periksa /up di backend; dari frontend coba login, muat kelas, logout, lalu buat data aktual dan uji satu siklus siswa–guru–laporan. Backup MySQL dan storage/app/private, kemudian uji restore.

Belum ada akun cPanel/Vercel yang diakses atau deployment yang dilakukan. Versi PHP hosting, ketersediaan Terminal/SSH/Composer dan URL proyek Vercel belum dikonfirmasi.

## Pindah domain

Backup database dan storage privat; pindahkan keduanya jika server ikut berubah. Pertahankan APP_KEY. Perbarui APP_URL dan URL frontend/origin stateful backend, API_BACKEND_URL di Vercel, DNS/SSL, lalu config:cache backend dan redeploy frontend. Pengguna masuk ulang pada domain baru. Tidak perlu mengubah nama domain di kode komponen.

## Pekerjaan lanjutan

Mesin LMS dan pengumpulan penelitian tersedia: courses/chapters/lessons, assignments/submissions/gallery, assessments/question-bank, gradebook, roster, reflections/followups dan research-studies/instruments/responses/exports. Instrumen/blueprint dan protokol aktual tetap disahkan peneliti sebelum pengukuran, lalu UAT dan analisis efektivitas dilakukan pada data lapangan.

## Baseline migrasi prarilis

Migrasi diringkas dari 16 menjadi 10 file. Username masuk ke pembuatan users; join_code, metadata materi, tinjauan latihan dan refleksi individu masuk ke foundation; snapshot LKPD dan followup masuk ke domain kelompok/aksi; metadata instrumen masuk ke pembuatan research_instruments. Cache, antrean, token, observasi, LKPD, diskusi dan domain LMS tetap terpisah. Perubahan users.school_id dan materials.course_lesson_id tetap dilakukan setelah tabel referensinya tersedia untuk menjaga urutan foreign key.

Instalasi kosong cukup menjalankan `php artisan migrate --force`. Database lokal yang sudah berisi data telah diselaraskan ke baseline ini tanpa membuat ulang tabel aplikasi. Seluruh 10 migrasi baseline berada di batch 1; perubahan berikutnya memakai migrasi baru pada batch berikutnya.

Untuk salinan database prarilis lama yang sudah menyelesaikan seluruh 16 migrasi, periksa melalui `php artisan svil:consolidate-migrations`, lalu jalankan dengan `--apply` untuk menyelaraskan metadata. Perintah menolak riwayat lama yang belum lengkap atau kolom baseline yang belum tersedia; migrasi lanjutan dipertahankan beserta batch-nya. Backup riwayat asal disimpan privat di storage/app/private/migration-history sebelum tabel migrations diubah; backup ini berisi riwayat migrasi, bukan backup seluruh data sekolah. Perintah bersifat idempoten.

Jangan menjalankan migrate:fresh atau rollback baseline pada database berisi data aktual. Rollback baseline menghapus tabelnya. Setelah ada instalasi production, perubahan skema dibuat sebagai migrasi baru agar database hosting dapat mengikuti upgrade tanpa penggabungan ulang riwayat.

## Pembaca dokumen dan API LMS

GET /materials/{id}/preview mengirim PDF inline privat. GET /materials/{id}/slides menghasilkan slide PPTX ringan; /slide-assets/{hash} hanya mengirim raster dari arsip yang sama setelah pemeriksaan akses kelas. File tetap di storage privat. PPTX memerlukan ekstensi ZIP dan DOM PHP; ukuran upload maksimal 10 MB, isi arsip diperiksa (maksimum 100 slide/80 MB isi), XML tanpa DTD/entity/makro. Tidak ada konversi cloud/LibreOffice. PDF direkomendasikan untuk tampilan PowerPoint kompleks.

GET /student-reviews?classroom_id={id} menampilkan nama murid dan kelengkapan LKPD; /student-reviews/{student}?classroom_id={id} memisahkan catatan/refleksi individu dari pekerjaan kelompok. Tinjauan catatan dapat memakai content_hash untuk menolak versi yang sudah berubah.

GET /gradebook dan /gradebook.csv memerlukan classroom_id, course_id opsional. Penilaian tugas, asesmen, rubrik kelompok dan observasi tidak digabung menjadi skor karakter.

Endpoint penelitian berada di /research-studies. Instrumen disimpan sebagai versi baru; pengukuran penelitian memerlukan tautan butir yang disahkan dan hash kanonik. Ekspor JSON menyimpan snapshot dan SHA-256, sehingga perubahan selanjutnya tidak mengubah laporan terdahulu. Teks bebas tetap direview sebelum penyerahan.

Untuk instalasi baru, gunakan svil:import-lkpd --by={email-admin}. Instalasi lama dapat memakai --upgrade untuk memperluas schema bawaan yang belum dikustomisasi. Snapshot pekerjaan kelompok lama tidak berubah.

## Operasional LMS yang diselesaikan

- **Akun:** pengaturan profil dan kata sandi mandiri; admin memulihkan akses dengan konfirmasi kata sandi admin. Sesi lama dicabut dan pengguna wajib mengganti kata sandi awal. Bantuan lupa password diarahkan ke admin; email/SMTP ditunda sesuai keputusan uji penelitian.
- **Kelas:** koreksi kelas/tahun ajaran, arsip baca saja, rotasi kode, nonaktifkan/pindahkan/aktifkan kembali anggota, ubah/arsip kelompok, serta naik kelas dengan salinan struktur opsional. Keanggotaan lama dan snapshot jawaban dipertahankan; perpindahan peserta riset aktif dibatasi.
- **Progres:** siswa menyatakan mulai/selesai; guru menentukan syarat materi, kegiatan atau keduanya dan ambang nilai opsional. Versi materi dan syarat terbaru diperiksa lagi. Status selesai bukan bukti otomatis memahami isi atau peningkatan karakter.
- **Tugas:** guru mengatur penerimaan terlambat, revisi, kunci setelah tinjauan, batas pengumpulan dan pembukaan ulang per siswa/kelompok dengan alasan serta masa berlaku.
- **Kabar:** notifikasi personal tersimpan, sudah/belum dibaca, materi/tugas/kuis, feedback/revisi, jadwal, pengumuman dan pengingat tenggat. Tidak menggunakan email/push.
- **Kalender/laporan:** agenda tugas/kuis/pertemuan, rekap kehadiran serta CSV/cetak, progres pribadi/per siswa dan riwayat kelas lama. Dashboard memasukkan tugas, asesmen dan antrean tinjauan.
- **Media:** guru/admin mengisi tautan YouTube LKPD per kelas; admin juga dapat mengatur video dasar LKPD. Materi menerima video YouTube dan tautan audio HTTPS. Video dimuat setelah siswa menekan putar, tidak diunggah ke database. Deskripsi tertulis tetap tersedia; sediakan takarir melalui video sumber. PDF/PPTX tetap privat.

### Backup dan pemulihan

Perintah berikut menggunakan executable MySQL di server. Jika tidak ada dalam PATH, isi lokasi melalui --dump-binary atau --mysql-binary. Backup mengaktifkan maintenance sementara untuk menyelaraskan SQL dan berkas, lalu mengembalikan keadaan sebelumnya.

    php artisan svil:backup
    php artisan svil:restore-backup /path/backup.zip
    php artisan down
    php artisan svil:restore-backup /path/backup.zip --apply --confirm-database=nama_database_tujuan
    php artisan up

Tanpa --apply, restore hanya memeriksa format/checksum. Pemulihan menimpa data tujuan: simpan backup tujuan terlebih dahulu. Arsip SQL dan berkas privat tersimpan di storage/app/private/backups, tanpa .env/APP_KEY; salin arsip ke penyimpanan privat terpisah. APP_KEY production disimpan terpisah. Uji restore ke database pengujian terpisah telah berhasil untuk backup aktual lokal. Untuk cPanel tanpa binary/CLI, gunakan backup MySQL dan berkas privat melalui panel/provider, lalu uji pemulihannya. Penjadwalan backup di hosting belum diaktifkan.

### Paket rilis

Jalankan PowerShell dari frontend:

    ./scripts/package-release.ps1

Paket di ../releases memuat backend beserta vendor production dan frontend source untuk Vercel, disertai SHA256SUMS.txt. Tidak memuat .env rahasia, data sekolah, berkas siswa, node_modules, test/cache lokal atau backup privat. Untuk menyiapkan source backend saja tersedia -SkipVendor, tetapi hosting kemudian wajib menjalankan Composer. Database kosong dan migrasi data lokal adalah dua jalur berbeda: instalasi kosong membuat admin/impor LKPD; pemindahan data memakai backup SQL + storage privat, kemudian migrate --force. Jangan menjalankan migrate:fresh pada data aktual.
