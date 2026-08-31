# Observium Docker - Custom Build

Deploy Observium Community Edition menggunakan Docker, di-build dari nol berbasis **Ubuntu 24.04** agar kompatibel dengan Docker/containerd modern.

## Prasyarat

- Docker Engine 20.10+
- Docker Compose 2.0+
- Koneksi internet (untuk download Observium tarball saat build)

## Quick Start

```bash
# 1. Clone / copy folder ini ke server
cd ~/observium-docker

# 2. Edit konfigurasi (WAJIB ganti password!)
nano .env

# 3. Build image
docker compose build

# 4. Jalankan
docker compose up -d

# 5. Monitor startup (tunggu sampai "Observium is ready!")
docker compose logs -f observium

# 6. Akses web interface
# http://<IP-SERVER>:8080
# Login: admin / <password yang di-set di .env>
```

## Konfigurasi

Edit file `.env` sebelum deploy:

| Variable | Default | Keterangan |
|---|---|---|
| `TZ` | `Asia/Jakarta` | Timezone |
| `OBSERVIUM_PORT` | `8080` | Port akses web |
| `MARIADB_ROOT_PASSWORD` | `changeme_root_password` | **WAJIB GANTI** |
| `DB_NAME` | `observium` | Nama database |
| `DB_USER` | `observium` | User database |
| `DB_PASSWORD` | `changeme_db_password` | **WAJIB GANTI** |
| `OBSERVIUM_ADMIN_USER` | `admin` | Username admin web |
| `OBSERVIUM_ADMIN_PASSWORD` | `changeme_admin_password` | **WAJIB GANTI** |
| `OBSERVIUM_SNMP_COMMUNITY` | `public` | SNMP community string |
| `DB_CONNECTION_TIMEOUT` | `90` | Timeout koneksi DB (detik) |

## Menambahkan Device

Setelah Observium berjalan, tambahkan device via web UI:

1. Login ke `http://<IP>:8080`
2. Klik **Devices** → **Add Device**
3. Masukkan hostname/IP dan SNMP community string
4. Klik **Add Device**

Atau via CLI:
```bash
docker exec -u www-data observium php /opt/observium/addhost.php <hostname> <community> v2c
```

## Perintah Berguna

```bash
# Lihat log
docker compose logs -f observium

# Restart Observium
docker compose restart observium

# Masuk ke container
docker exec -it observium bash

# Manual discovery
docker exec -u www-data observium php /opt/observium/discovery.php -h all

# Manual polling
docker exec -u www-data observium php /opt/observium/poller.php -h all

# Update Observium (rebuild image)
docker compose build --no-cache
docker compose up -d

# Backup database
docker exec observium-db mariadb-dump -u root -p<password> observium > backup.sql
```

## Struktur File

```
.
├── Dockerfile              # Image definition (Ubuntu 24.04 + Apache + PHP + Observium)
├── docker-compose.yml      # Stack: observium + mariadb
├── .env                    # Environment variables (passwords, config)
├── entrypoint.sh           # Init script (DB wait, config, schema, cron, Apache)
├── observium-apache.conf   # Apache VirtualHost config
├── .gitignore              # Ignore .env dan file temporary
└── README.md               # Dokumentasi ini
```

## Volumes

| Volume | Path di Container | Keterangan |
|---|---|---|
| `observium-rrd` | `/opt/observium/rrd` | Data grafik RRD |
| `observium-logs` | `/opt/observium/logs` | Log aplikasi |
| `observium-db` | `/var/lib/mysql` | Data MariaDB |

## Troubleshooting

### Build gagal download tarball
Pastikan server punya akses internet ke `www.observium.org`. Jika di belakang proxy, tambahkan `--build-arg` untuk HTTP_PROXY.

### Container restart loop
Cek log: `docker compose logs observium`. Biasanya karena MariaDB belum siap. Tingkatkan `DB_CONNECTION_TIMEOUT` di `.env`.

### Permission error pada RRD
```bash
docker exec observium chown -R www-data:www-data /opt/observium/rrd
```
