# 📬 PMG Dashboard

Dashboard web para monitoramento em tempo real do **Proxmox Mail Gateway (PMG)**
com suporte a múltiplos idiomas e autenticação em duas etapas.


<img width="1644" height="946" alt="image" src="https://github.com/user-attachments/assets/3aed191c-463d-42e5-b3c7-0ba83f971a2a" />




## Recursos

- 🎨 Interface glassmorphism responsiva (sem rolagem)
- 🌐 Multi-idioma (pt-BR, en-US, es-ES) com detecção automática
- 🔐 Login por e-mail (operador) e por usuário+senha em `/admin`
- 📧 2FA por e-mail via PHPMailer/SMTP
- 🛡️ Cloudflare Turnstile (ativável/desativável)
- 📊 Gráfico das últimas 24h (Recebidos, Spam, Taxa Spam, Taxa PreGREET)
- 🏆 Top 15 remetentes e domínios
- 📅 Seletor de período: Diário / Mensal / Semestral / Anual
- 👥 Gerenciamento de usuários com perfis admin e operador
- 🌍 Status das portas de relay e IP público do PMG

## Requisitos

- PHP 7.4+ (recomendado 8.1+) com `curl`, `mysql`, `json`, `openssl`, `mbstring`
- MySQL 5.7+ ou MariaDB 10.3+
- Apache 2.4+ ou Nginx
- Composer
- Acesso HTTPS ao PMG (porta 8006)

## Instalação

1. **Dependências:**
   ```bash
   sudo apt install apache2 mysql-server php php-curl php-mysql php-json php-mbstring composer
   sudo a2enmod rewrite && sudo systemctl restart apache2

## Criar banco de dados MySQL

CREATE DATABASE pmg_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pmg_dashboard'@'localhost' IDENTIFIED BY 'SenhaForte';
GRANT ALL PRIVILEGES ON pmg_dashboard.* TO 'pmg_dashboard'@'localhost';
ALTER TABLE users ADD COLUMN language VARCHAR(10) DEFAULT NULL;
FLUSH PRIVILEGES;

## Criar usuário de consulta a API do PMG

pmgsh create /access/users --userid dashboard@pmg --role audit --password 'SenhaForte'

pmgsh set /access/users/dashboard@pmg --enable 1

# Limpar cache do gráfico

rm -f /tmp/pmg_dashboard_hourly*.json

# Atualizar PHPMailer

composer update phpmailer/phpmailer


# Ao concluir a instalação do sistema e criação do usuário admin remova ou renomeie o arquivo install.php

rm /var/www/html/dashboard/install.php
