
# ================================================
# STATUSLY REFERRAL SIMULATION
# Local sandbox testing only
# ================================================

$ErrorActionPreference = "Stop"

$BaseUrl = "http://127.0.0.1:8000/api"

# Replace this with the referral code displayed
# on your Samsung device.
$InviterCode = "RBF4MX"

# Three separate simulated Android devices.
# These IDs must not belong to real devices.
$Devices = @(
    @{
        Name = "Referral Device B"
        AndroidId = "a1b2c3d4e5f60011"
    },
    @{
        Name = "Referral Device C"
        AndroidId = "a1b2c3d4e5f60012"
    },
    @{
        Name = "Referral Device D"
        AndroidId = "a1b2c3d4e5f60013"
    }
)

function Send-JsonRequest {
    param(
        [string]$Path,
        [hashtable]$Body,
        [string]$Token = ""
    )

    $Headers = @{
        Accept = "application/json"
    }

    if ($Token -ne "") {
        $Headers["Authorization"] = "Bearer $Token"
    }

    $Json = $Body | ConvertTo-Json -Depth 10

    return Invoke-RestMethod `
        -Uri "$BaseUrl$Path" `
        -Method Post `
        -Headers $Headers `
        -ContentType "application/json" `
        -Body $Json
}

function New-ContentHash {
    param(
        [string]$Content
    )

    $Bytes = [System.Text.Encoding]::UTF8.GetBytes(
        $Content
    )

    $Sha = [System.Security.Cryptography.SHA256]::Create()

    try {
        $HashBytes = $Sha.ComputeHash($Bytes)
        return [System.BitConverter]::ToString(
            $HashBytes
        ).Replace("-", "").ToLowerInvariant()
    }
    finally {
        $Sha.Dispose()
    }
}

Write-Host ""
Write-Host "STATUSLY REFERRAL TEST" -ForegroundColor Cyan
Write-Host "======================" -ForegroundColor Cyan
Write-Host "Inviter code: $InviterCode"
Write-Host ""

# Unique batch prevents previous test saves from
# accidentally being reused in this run.
$BatchId = [guid]::NewGuid().ToString("N")

foreach ($Device in $Devices) {

    Write-Host ""
    Write-Host "--------------------------------"
    Write-Host $Device.Name -ForegroundColor Yellow
    Write-Host "--------------------------------"

    # 1. Register device
    $Registration = Send-JsonRequest `
        -Path "/rewards/devices/register" `
        -Body @{
            android_id = $Device.AndroidId
        }

    $Token = $Registration.token

    Write-Host "Registered successfully."
    Write-Host "New device: $($Registration.new_device)"
    Write-Host "Referral code: $($Registration.device.referral_code)"

    if (-not $Registration.new_device) {
        Write-Warning "This device already exists. Its referral claim may not qualify as a new referral."
    }

    # 2. Claim inviter referral code
    $Claim = Send-JsonRequest `
        -Path "/rewards/referrals/claim" `
        -Token $Token `
        -Body @{
            referral_code = $InviterCode
        }

    Write-Host "Referral claim response:"
    $Claim | ConvertTo-Json -Depth 10

    # 3. Submit two unique simulated saves
    for ($SaveNumber = 1; $SaveNumber -le 2; $SaveNumber++) {

        $UniqueContent = "$BatchId-$($Device.AndroidId)-$SaveNumber"

        $ContentHash = New-ContentHash `
            -Content $UniqueContent

        $Save = Send-JsonRequest `
            -Path "/rewards/saves" `
            -Token $Token `
            -Body @{
                content_hash = $ContentHash
                media_type = "images"
                source = "whatsapp"
                size_bytes = 125000
            }

        Write-Host ""
        Write-Host "Save $SaveNumber response:"
        $Save | ConvertTo-Json -Depth 10
    }

    Write-Host ""
    Write-Host "$($Device.Name) completed." `
        -ForegroundColor Green
}

Write-Host ""
Write-Host "All simulated requests completed." `
    -ForegroundColor Green
Write-Host "Refresh Campaign Progress on Samsung."
