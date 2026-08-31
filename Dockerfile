# =============================================================================
# Observium Community Edition - Custom Docker Image
# Base: Ubuntu 24.04 LTS (Noble Numbat)
# =============================================================================
FROM ubuntu:24.04

LABEL maintainer="observium-docker"
LABEL description="Observium Community Edition on Ubuntu 24.04 with Apache + PHP 8.3"

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Default timezone (can be overridden via .env)
ENV TZ=Asia/Jakarta

# -----------------------------------------------------------------------------
# 1. Install all required packages
# -----------------------------------------------------------------------------
RUN apt-get update && \
    apt-get -y dist-upgrade && \
    apt-get install -y --no-install-recommends \
    # Web server & PHP
    apache2 \
    libapache2-mod-php8.3 \
    php8.3-cli \
    php8.3-mysql \
    php8.3-mysqli \
    php8.3-gd \
    php8.3-bcmath \
    php8.3-mbstring \
    php8.3-opcache \
    php8.3-apcu \
    php8.3-curl \
    php8.3-xml \
    php8.3-zip \
    php-json \
    php-pear \
    # SNMP & monitoring tools
    snmp \
    snmp-mibs-downloader \
    fping \
    rrdtool \
    mtr-tiny \
    ipmitool \
    graphviz \
    imagemagick \
    whois \
    traceroute \
    # Database client
    mariadb-client \
    # Python (needed by Observium for some functions)
    python3 \
    python3-mysqldb \
    python3-pymysql \
    python-is-python3 \
    # Utilities
    curl \
    wget \
    cron \
    sudo \
    ca-certificates \
    tzdata \
    && \
    # Clean up APT cache
    apt-get clean autoclean && \
    apt-get autoremove -y && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# -----------------------------------------------------------------------------
# 2. Download & install Observium Community Edition
# -----------------------------------------------------------------------------
RUN mkdir -p /opt/observium && \
    cd /tmp && \
    wget -q http://www.observium.org/observium-community-latest.tar.gz && \
    tar xzf observium-community-latest.tar.gz -C /opt/ && \
    rm -f observium-community-latest.tar.gz

# Create required directories
RUN mkdir -p /opt/observium/rrd \
             /opt/observium/logs \
             /opt/observium/mibs \
    && chown -R www-data:www-data /opt/observium/rrd \
    && chown -R www-data:www-data /opt/observium/logs

# -----------------------------------------------------------------------------
# 3. Configure Apache
# -----------------------------------------------------------------------------
# Remove default site, enable required modules
RUN a2dissite 000-default.conf && \
    a2enmod rewrite && \
    a2enmod php8.3

# Copy custom Apache vhost config
COPY observium-apache.conf /etc/apache2/sites-available/observium.conf
RUN a2ensite observium.conf

# Change Apache to listen on port 8080 (non-privileged)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

# Fix ServerName warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Fix SNMP MIBs
RUN sed -i 's/^mibs :$/# mibs :/' /etc/snmp/snmp.conf 2>/dev/null || true

# -----------------------------------------------------------------------------
# 4. Copy entrypoint script
# -----------------------------------------------------------------------------
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# -----------------------------------------------------------------------------
# 5. Set working directory & expose port
# -----------------------------------------------------------------------------
WORKDIR /opt/observium

EXPOSE 8080

# -----------------------------------------------------------------------------
# 6. Healthcheck
# -----------------------------------------------------------------------------
HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

ENTRYPOINT ["/entrypoint.sh"]
