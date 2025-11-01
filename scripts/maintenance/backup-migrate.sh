#!/bin/bash

# ============================================
# FolyoAggregator - Script de Backup e Migração
# ============================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
PROJECT_DIR="/var/www/html/folyoaggregator"
BACKUP_DIR="$HOME/folyoaggregator_backup_$TIMESTAMP"

echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     FOLYOAGGREGATOR - BACKUP & MIGRAÇÃO           ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""

# Parse arguments
ACTION=$1

if [ "$ACTION" == "backup" ]; then
    echo -e "${YELLOW}📦 CRIANDO BACKUP COMPLETO...${NC}"

    # Create backup directory
    mkdir -p "$BACKUP_DIR"

    # 1. Backup Database
    echo -e "${GREEN}→ Exportando banco de dados...${NC}"
    DB_NAME=$(grep DB_NAME "$PROJECT_DIR/.env" | cut -d '=' -f2)
    DB_USER=$(grep DB_USER "$PROJECT_DIR/.env" | cut -d '=' -f2)
    DB_PASS=$(grep DB_PASSWORD "$PROJECT_DIR/.env" | cut -d '=' -f2)

    mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_DIR/database.sql"

    # Compress database
    gzip "$BACKUP_DIR/database.sql"

    # 2. Backup Code
    echo -e "${GREEN}→ Copiando código fonte...${NC}"
    rsync -av --exclude='vendor' --exclude='logs/*' --exclude='.git' \
        "$PROJECT_DIR/" "$BACKUP_DIR/code/"

    # 3. Save environment info
    echo -e "${GREEN}→ Salvando informações do ambiente...${NC}"
    cat > "$BACKUP_DIR/environment.txt" <<EOF
BACKUP DATE: $(date)
PHP VERSION: $(php -v | head -n 1)
MYSQL VERSION: $(mysql --version)
COMPOSER PACKAGES: See composer.json
EOF

    # 4. Create migration instructions
    cat > "$BACKUP_DIR/MIGRATE_INSTRUCTIONS.md" <<'EOF'
# 🚀 Como Migrar para Novo Servidor

## 1. Requisitos no Novo Servidor
- Ubuntu/Debian (ou similar)
- Apache2 com mod_rewrite
- PHP 8.1+
- MariaDB/MySQL
- Composer

## 2. Instalar Dependências
```bash
sudo apt update
sudo apt install apache2 php php-mysql php-curl php-json php-mbstring mariadb-server composer
sudo a2enmod rewrite
```

## 3. Criar Banco de Dados
```bash
mysql -u root -p
CREATE DATABASE folyoaggregator;
CREATE USER 'folyo'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON folyoaggregator.* TO 'folyo'@'localhost';
FLUSH PRIVILEGES;
```

## 4. Restaurar Dados
```bash
# Descompactar e importar banco
gunzip database.sql.gz
mysql -u folyo -p folyoaggregator < database.sql

# Copiar código
sudo cp -r code/ /var/www/html/folyoaggregator/
sudo chown -R www-data:www-data /var/www/html/folyoaggregator/

# Instalar dependências PHP
cd /var/www/html/folyoaggregator
composer install
```

## 5. Configurar Apache
```bash
# Criar VirtualHost
sudo nano /etc/apache2/sites-available/folyoaggregator.conf
# Copiar configuração do arquivo apache-vhost.conf

sudo a2ensite folyoaggregator
sudo systemctl reload apache2
```

## 6. Ajustar .env
```bash
cd /var/www/html/folyoaggregator
cp .env.example .env
nano .env
# Ajustar DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
```

## 7. Iniciar Coletor
```bash
# Cron para coleta contínua
crontab -e
# Adicionar:
* * * * * /usr/bin/php /var/www/html/folyoaggregator/scripts/price-collector.php >> /var/www/html/folyoaggregator/logs/collector.log 2>&1
```

## 8. Testar
```bash
curl http://localhost/api/v1/stats
```

PRONTO! 🎉
EOF

    # 5. Save Apache config
    echo -e "${GREEN}→ Salvando configuração Apache...${NC}"
    cat > "$BACKUP_DIR/apache-vhost.conf" <<'EOF'
<VirtualHost *:80>
    ServerName folyoaggregator.test
    ServerAlias www.folyoaggregator.test
    DocumentRoot /var/www/html/folyoaggregator/public

    <Directory /var/www/html/folyoaggregator/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/folyoaggregator_error.log
    CustomLog ${APACHE_LOG_DIR}/folyoaggregator_access.log combined
</VirtualHost>
EOF

    # 6. Create final archive
    echo -e "${GREEN}→ Criando arquivo final...${NC}"
    cd "$HOME"
    tar -czf "folyoaggregator_backup_$TIMESTAMP.tar.gz" "folyoaggregator_backup_$TIMESTAMP/"

    # Calculate size
    BACKUP_SIZE=$(du -h "folyoaggregator_backup_$TIMESTAMP.tar.gz" | cut -f1)

    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}✅ BACKUP COMPLETO!${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
    echo -e "📦 Arquivo: ${BLUE}$HOME/folyoaggregator_backup_$TIMESTAMP.tar.gz${NC}"
    echo -e "📊 Tamanho: ${YELLOW}$BACKUP_SIZE${NC}"
    echo -e "📝 Instruções: ${BLUE}$BACKUP_DIR/MIGRATE_INSTRUCTIONS.md${NC}"
    echo ""
    echo -e "${YELLOW}Para transferir para outro servidor:${NC}"
    echo -e "scp ~/folyoaggregator_backup_$TIMESTAMP.tar.gz user@novo-servidor:~/"

elif [ "$ACTION" == "restore" ]; then
    BACKUP_FILE=$2

    if [ -z "$BACKUP_FILE" ]; then
        echo -e "${RED}Erro: Especifique o arquivo de backup${NC}"
        echo "Uso: ./backup-migrate.sh restore <arquivo.tar.gz>"
        exit 1
    fi

    echo -e "${YELLOW}🔄 RESTAURANDO BACKUP...${NC}"

    # Extract backup
    echo -e "${GREEN}→ Extraindo arquivo...${NC}"
    tar -xzf "$BACKUP_FILE"

    BACKUP_FOLDER=$(tar -tzf "$BACKUP_FILE" | head -1 | cut -f1 -d"/")

    # Restore database
    echo -e "${GREEN}→ Restaurando banco de dados...${NC}"
    gunzip "$BACKUP_FOLDER/database.sql.gz"

    echo -e "${YELLOW}Digite a senha do MySQL:${NC}"
    mysql -u root -p folyoaggregator < "$BACKUP_FOLDER/database.sql"

    # Restore code
    echo -e "${GREEN}→ Restaurando código...${NC}"
    sudo cp -r "$BACKUP_FOLDER/code/" /var/www/html/folyoaggregator/
    sudo chown -R www-data:www-data /var/www/html/folyoaggregator/

    # Install dependencies
    echo -e "${GREEN}→ Instalando dependências...${NC}"
    cd /var/www/html/folyoaggregator
    composer install

    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}✅ RESTAURAÇÃO COMPLETA!${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}Lembre-se de:${NC}"
    echo -e "1. Ajustar o arquivo .env"
    echo -e "2. Configurar o Apache VirtualHost"
    echo -e "3. Adicionar ao crontab"

elif [ "$ACTION" == "quick-export" ]; then
    echo -e "${YELLOW}⚡ EXPORTAÇÃO RÁPIDA (apenas banco)...${NC}"

    DB_NAME=$(grep DB_NAME "$PROJECT_DIR/.env" | cut -d '=' -f2)
    DB_USER=$(grep DB_USER "$PROJECT_DIR/.env" | cut -d '=' -f2)
    DB_PASS=$(grep DB_PASSWORD "$PROJECT_DIR/.env" | cut -d '=' -f2)

    EXPORT_FILE="$HOME/folyoaggregator_db_$TIMESTAMP.sql.gz"

    echo -e "${GREEN}→ Exportando banco de dados...${NC}"
    mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$EXPORT_FILE"

    DB_SIZE=$(du -h "$EXPORT_FILE" | cut -f1)

    echo -e "${GREEN}✅ Banco exportado: $EXPORT_FILE ($DB_SIZE)${NC}"
    echo -e "${YELLOW}Para importar em outro servidor:${NC}"
    echo "gunzip < $EXPORT_FILE | mysql -u user -p database_name"

else
    echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}USO DO SCRIPT:${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "${YELLOW}./backup-migrate.sh backup${NC}"
    echo "  → Cria backup completo (código + banco + configs)"
    echo ""
    echo -e "${YELLOW}./backup-migrate.sh restore <arquivo.tar.gz>${NC}"
    echo "  → Restaura backup em novo servidor"
    echo ""
    echo -e "${YELLOW}./backup-migrate.sh quick-export${NC}"
    echo "  → Exporta apenas o banco de dados"
    echo ""
fi