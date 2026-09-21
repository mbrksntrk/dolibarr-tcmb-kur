# TCMB Döviz Kurları — Dolibarr için Merkez Bankası kur modülü

[![M8ven Score](https://m8ven.ai/badge/mcp/mbrksntrk-dolibarr-tcmb-kur-1v7xoy)](https://m8ven.ai/mcp/mbrksntrk-dolibarr-tcmb-kur-1v7xoy)

Türkiye Cumhuriyet Merkez Bankası'nın günlük **Gösterge Niteliğindeki Kurlar** bültenini Dolibarr'ın Çoklu Para Birimi kur tablosuna aktarır. Çekirdek sınıflarla yazar (doğrudan SQL yok), çekirdeğe müdahale yok.

| | |
|---|---|
| Dolibarr | 20.0+ (24.0 üzerinde geliştirildi ve test edildi), Çoklu Para Birimi modülü açık |
| PHP | 8.1+, `curl`, `simplexml` |
| Lisans | GPL-3.0-or-later + yazar atfı şartı ([ATTRIBUTION.md](ATTRIBUTION.md)) |
| Sürüm | 1.0.0 |

Ayrıntılar: [Wiki](https://github.com/mbrksntrk/dolibarr-tcmb-kur/wiki).

## Neler yapıyor

- **Tüm para birimleri:** bültendeki 20+ para biriminden seçtikleriniz (varsayılan USD, EUR, GBP); Dolibarr'da tanımsızsa otomatik eklenir
- **Kur türü seçimi:** Döviz Alış (VUK 280 uygulaması), Döviz Satış, Efektif Alış/Satış, Alış-Satış ortalaması
- **Doğru tarih:** bülten D tarihli, kur D+1 iş günü ile kaydedilir (ayarla kapatılabilir); hafta sonu atlanır. Dolibarr `MULTICURRENCY_USE_RATE_ON_DOCUMENT_DATE` ile belge tarihine göre doğru kuru bulur
- **Geçmişi doldurma:** son N günün bültenleri TCMB arşivinden (`kurlar/YYYYMM/DDMMYYYY.xml`) çekilir, eksik günler tamamlanır; tatil günleri atlanır, belirli tarih istenirse son yayınlanan bültene düşülür
- **TRY dışı ana para birimi:** şirket EUR/USD ile çalışıyorsa seçilen para birimleri ve TRY için çapraz kur hesaplanır
- **Günlük zamanlanmış görev** (varsayılan açık; 16:00 sonrasına ayarlayın), aynı tarihe mükerrer yazmama
- **Ayar sayfası:** güncel bülten tablosu (tüm kurlar + Dolibarr'a yazılacak değer + son kayıt), "Şimdi aktar", "Geçmişi doldur", çalışma günlüğü
- **Denetim:** her çalışma `llx_tcmbkur_log`'a; ayar değişiklikleri güvenlik denetim günlüğüne
- **MCP araçları** (Dolibarr 24 AI modülü): `tcmb_rates` ("15 Eylül USD kuru?"), `tcmb_sync`
- Her davranış ayarlarda; kodda kuruluma özgü değer yok

## Kurulum

1. `htdocs/custom/tcmbkur/` altına kopyalayın ya da [Releases](https://github.com/mbrksntrk/dolibarr-tcmb-kur/releases) ZIP'ini Kurulum → Modüller → **Harici modül yükle** ile kurun.
2. Çoklu Para Birimi modülü açık olsun; Kurulum → Modüller → **TCMB Döviz Kurları** → aktif edin.
3. Ayarlar (⚙): para birimleri, kur türü, tarih davranışı. **Geçmişi doldur** ile son 30 günü çekin.
4. Kurulum → Zamanlanmış görevler → *TcmbKurDailySync* saatini 16:00 sonrasına alın. Dolibarr'ın kendi "Tüm döviz kurlarını güncelle" görevini kapatın (ECB kaynaklı, TCMB ile çakışır).
5. Yetkiler: *TCMB kurlarını görüntüle* / *güncelle*.

## Muhasebe notu

VUK 280 uyarınca değerlemede TCMB **döviz alış** kuru esas alınır ve bir günün işlemlerinde **bir önceki iş gününün** bülteni kullanılır. Varsayılan ayarlar (Döviz Alış + ertesi iş günü) bu uygulamayla uyumludur; farklı bir politika için ayarlardan değiştirin. Efektif kurlar nakit işlemler içindir.

## Dolibarr kur yönü

Dolibarr'da `rate` = 1 ana para birimi karşılığı yabancı para birimi (TRY ana birimde USD için ≈ 0,0206), `rate_direct` = 1 yabancı birim karşılığı ana para (≈ 48,59 TRY). Modül ikisini de doğru yönde yazar; JPY gibi 100 birimlik kurlar birim başına indirgenir.

## MCP araçları (Dolibarr 24 AI modülü)

| Araç | Ne yapar |
|---|---|
| `tcmb_rates` | Bir tarihin bülteni: alış/satış/efektif kurlar, Dolibarr'a yazılacak değer, Dolibarr'daki son kayıt (salt okunur) |
| `tcmb_sync` | Son (ya da belirli tarihli) bülteni Dolibarr'a yaz; `backfill_days` ile geçmişi doldur |

## Katkı

Sorun ve öneriler için [GitHub Issues](https://github.com/mbrksntrk/dolibarr-tcmb-kur/issues). Kod stili Dolibarr standardı; çekirdeğe dokunmadan hook/cron/kendi tablosu.

## Lisans

GPL-3.0-or-later ([COPYING](COPYING)). GPLv3 7(b) maddesi kapsamında ek şart: yazar atfı korunmalıdır — ayrıntı [ATTRIBUTION.md](ATTRIBUTION.md). Ücretsiz ve ticari kullanım, değiştirme ve dağıtım serbesttir.

## Yazar

**M. Burak Şentürk**
- Web: [buraksenturk.net](https://buraksenturk.net)
- E-posta: mburaksenturk@gmail.com
- GitHub: [@mbrksntrk](https://github.com/mbrksntrk)
- Proje: [github.com/mbrksntrk/dolibarr-tcmb-kur](https://github.com/mbrksntrk/dolibarr-tcmb-kur)

Bu proje TCMB ile bağlantılı değildir; veriler TCMB'nin herkese açık bülteninden alınır.

---

**English:** Dolibarr module importing the Central Bank of Türkiye (TCMB) daily indicative exchange-rate bulletin into Dolibarr multicurrency rates: any bulletin currency, choice of buying/selling/banknote/average rate, correct dating (bulletin date or next business day as Turkish tax practice requires), archive backfill with holiday fallback, cross rates for non-TRY company currencies, daily cron, run log, MCP tools for the Dolibarr 24 AI module. Written through core classes, no core modification. Author: M. Burak Şentürk — https://buraksenturk.net. License: GPL-3.0-or-later with an attribution-preservation term (see ATTRIBUTION.md).
