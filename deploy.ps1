<#
.SYNOPSIS
  Deploy auth-web to remote server via SSH.
.DESCRIPTION
  Reads config from .env, packs project source via tar and syncs
  to remote server over SSH. Excludes dev files (DB, .git, logs).
  Sets write permissions on data/ after deploy.
.PARAMETER DryRun
  Show commands without executing.
.EXAMPLE
  .\deploy.ps1
  .\deploy.ps1 -DryRun
#>

param(
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# --- 1. Load .env ---
$envFile = Join-Path $PSScriptRoot '.env'
if (Test-Path $envFile) {
  Get-Content $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+?)\s*$') {
      [Environment]::SetEnvironmentVariable($matches[1], $matches[2])
    }
  }
}

$sshHost    = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_HOST')
$sshPort    = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_PORT')
if (-not $sshPort) { $sshPort = '22' }
$sshUser    = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_USER')
$remotePath = [Environment]::GetEnvironmentVariable('DEPLOY_REMOTE_PATH')
if ($remotePath) { $remotePath = $remotePath.TrimEnd('/') }

if (-not $sshHost -or -not $sshUser -or -not $remotePath) {
  Write-Host "ERROR: Set DEPLOY_SSH_HOST, DEPLOY_SSH_USER and DEPLOY_REMOTE_PATH in .env" -ForegroundColor Red
  exit 1
}

$webUser    = [Environment]::GetEnvironmentVariable('DEPLOY_WEB_USER')
if (-not $webUser) { $webUser = 'www-data' }

$identityFile = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_KEY')
$identityArg  = if ($identityFile) { "-i `"$identityFile`"" } else { '' }

$remote  = "${sshUser}@${sshHost}"
$portArg = if ($sshPort -ne '22') { "-P $sshPort" } else { '' }

# Fix SSH key permissions (Windows OpenSSH requires restrictive ACLs)
if ($identityFile -and (Test-Path $identityFile)) {
  $identityFullPath = (Resolve-Path $identityFile).Path
  icacls $identityFullPath /reset           2>$null
  icacls $identityFullPath /inheritance:r    2>$null
  icacls $identityFullPath /grant "${env:USERNAME}:(R)" 2>$null
}

# --- 2. Pack & deploy via tar + ssh ---
$srcPath = $PSScriptRoot

$excludeArgs = @(
  '--exclude=.git',
  '--exclude=.gitignore',
  '--exclude=.gitattributes',
  '--exclude=.vscode',
  '--exclude=.idea',
  '--exclude=*.swp',
  '--exclude=*.swo',
  '--exclude=*~',
  '--exclude=Thumbs.db',
  '--exclude=.DS_Store',
  '--exclude=Desktop.ini',
  '--exclude=data/auth.db',
  '--exclude=data/auth.db-wal',
  '--exclude=data/auth.db-shm',
  '--exclude=*.log',
  '--exclude=php_errors.log',
  '--exclude=.env',
  '--exclude=deploy.ps1',
  '--exclude=node_modules',
  '--exclude=.editorconfig'
) -join ' '

$tarCmd = "tar czf - $excludeArgs -C `"$srcPath`" ."
$preDeployCmd = "mkdir -p /tmp/auth-backup; cp -f ${remotePath}/data/auth.db ${remotePath}/data/auth.db-wal ${remotePath}/data/auth.db-shm /tmp/auth-backup/ 2>/dev/null || true; find ${remotePath} -mindepth 1 -delete 2>/dev/null || true"
$postDeployCmd = "mkdir -p ${remotePath}/data; cp -f /tmp/auth-backup/auth.db /tmp/auth-backup/auth.db-wal /tmp/auth-backup/auth.db-shm ${remotePath}/data/ 2>/dev/null || true; chown -R ${webUser}:${webUser} ${remotePath}; chmod -R 775 ${remotePath}/data; find ${remotePath}/data -type f -name '*.db' -exec chmod 664 {} \; ; rm -rf /tmp/auth-backup"
$sshCmd = "ssh $portArg $identityArg $remote `"${preDeployCmd}; tar -xzf - -C ${remotePath}; ${postDeployCmd}`""

Write-Host "`n==> Deploying to ${remote}:${remotePath} ..." -ForegroundColor Cyan

if ($DryRun) {
  Write-Host "  [DryRun] $tarCmd | $sshCmd" -ForegroundColor Yellow
} else {
  Write-Host "  Archiving and transferring..." -ForegroundColor Gray
  $pipe = "$tarCmd | $sshCmd"
  cmd /c $pipe
  if ($LASTEXITCODE -ne 0) {
    Write-Host "Deploy failed (exit code: $LASTEXITCODE)" -ForegroundColor Red
    exit 1
  }
  Write-Host "  Done." -ForegroundColor Green
}

Write-Host "`n==> Deploy complete" -ForegroundColor Green
