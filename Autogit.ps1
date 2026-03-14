# Gitea Auto Push Script (Dynamic Username/Organization)
Write-Host "--- Git Auto Setup & Push Script ---" -ForegroundColor Cyan

# Username ba Organization Name input newa
$UserName = Read-Host "Enter Username or Organization (e.g., Somnath or php)"

# Repository Name input newa
$RepoName = Read-Host "Enter Repository Name (e.g., wepage)"

# Server URL toiri kora
$ServerUrl = "http://192.168.0.111/$UserName/$RepoName.git"

Write-Host "`n[1/5] Git Initialize kora hocche..." -ForegroundColor Yellow
git init

Write-Host "[2/5] File Add kora hocche..." -ForegroundColor Yellow
git add .

Write-Host "[3/5] Code Commit kora hocche..." -ForegroundColor Yellow
git commit -m "Initial auto-commit"

Write-Host "[4/5] Branch 'main' set kora hocche..." -ForegroundColor Yellow
git branch -M main

Write-Host "[5/5] Remote Origin set kore Push kora hocche..." -ForegroundColor Yellow
# Ager origin thakle seta update korbe, na thakle notun kore add korbe
$remoteExists = git remote
if ($remoteExists -contains "origin") {
    git remote set-url origin $ServerUrl
} else {
    git remote add origin $ServerUrl
}

# Code push kora
git push -u origin main

Write-Host "`n✅ Success! Code successfully $UserName er under e push hoyeche." -ForegroundColor Green