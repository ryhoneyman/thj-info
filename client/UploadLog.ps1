<#
.SYNOPSIS
    Uploads log lines to the API for processing.
.DESCRIPTION
    This script monitors a log file for specific phrases and uploads matching lines to an API for processing.
    It handles authentication, downloading match phrases, and uploading log lines.
.PARAMETER CharacterName
    The name of the character to be used in the script.
.PARAMETER LogDir
    The directory where the log file is located.
.PARAMETER ConfigFile
    The name of the configuration file.
    The default is "config.json".
.PARAMETER Init
    Initializes the script to create configuration.
.EXAMPLE
    .\UploadLog.ps1 -CharacterName "MyCharacter" -LogDir "C:\Logs"
    This example runs the script with the specified character name and log directory.
.EXAMPLE
    .\UploadLog.ps1 -Init
    This example initializes the script to create configuration.
#>
param(
    [Parameter(Mandatory=$false,
               HelpMessage="Enter the character's name.")]
    [string]$CharacterName = $null,

    [Parameter(Mandatory=$false,
               HelpMessage="Specify the full path to the log directory.")]
    [string]$LogDir = $null,

    [Parameter(Mandatory=$false,
               HelpMessage="Specify the configuration file name.")]
    [string]$ConfigFile = "config.json",

    [Parameter(HelpMessage="Initialize the script to create configuration.")]
    [switch]$Init
)

###
### Supporting functions
###
function Warning {
	param (
        [Parameter(Mandatory=$true)] 
        [string]$message
    )
    Print-Message -message $message -label "WARN" -fgColor "yellow"
}

function Notice {
	param (
        [Parameter(Mandatory=$true)] 
        [string]$message
    )
    Print-Message -message $message -label "NOTICE" -fgColor "green"
}

function Fatal {
	param (
        [Parameter(Mandatory=$true)] 
        [string]$message
    )
    Print-Message -message $message -label "FATAL" -fgColor "red" -exit $true
	Read-Host -Prompt "Press ENTER to Exit"
}

function Print-Message {
    param (
        [string]$message,
        [string]$label = "NOTICE",
		[string]$fgColor = "yellow",
		[bool]$exit = $false
    )
    Write-Host "[$label] $message" -ForegroundColor $fgColor -BackgroundColor Black
	
	if ($exit) {
		Exit 1
	}
}

function Build-Headers {
	$authHeaders = @{} 

	# Check if apiHeaders actually exists in the config and has properties
	if ($null -ne $config.apiHeaders) {
		$config.apiHeaders.PSObject.Properties | ForEach-Object {
			$authHeaders[$_.Name] = $_.Value
		}
	}
	
	return $authHeaders
}

function Authenticate-API {
    if (-not $credentials.client_id) {
        Fatal "Value for client_id is not set in the credentials file.  Did you register on Discord?"
    }
    if (-not $credentials.client_secret) {
        Fatal "Value for client_secret is not set in the credentials file.  Did you register on Discord?"
    }

    $payload = @{
        client_id     = $credentials.client_id
        client_secret = $credentials.client_secret
    } | ConvertTo-Json -Depth 2 -Compress

    try {
        # Send the line to the API
		$headers  =  Build-Headers
        $response = Invoke-RestMethod -Uri "$($config.apiBaseUrl)/auth/token" -Method POST -Headers $headers -Body $payload
	
        # Check if the response contains an access token
        if ($response.token) {
            # Store the access token in the configuration
			Add-Member -InputObject $credentials -MemberType NoteProperty -Name 'token' -Value $response.token -Force
			Add-Member -InputObject $config.apiHeaders -MemberType NoteProperty -Name 'Authorization' -Value "Bearer $($credentials.token)" -Force

            return $true
        }
        else {
            Warning "Authentication failed. No token received."
            return $false
        }
           
    } catch {
        Warning "Authentication failed: $($_.Exception.Response.StatusCode) $($_.Exception.Message)"
        return $false
    }
}

function Download-MatchPhrases {
    try {
        # Send the line to the API
		$headers  = Build-Headers
        $response = Invoke-RestMethod -Uri "$($config.apiBaseUrl)/log/phrases" -Method GET -Headers $headers
		
		if ($response.data) {
			Add-Member -InputObject $config -MemberType NoteProperty -Name 'matchPhrases' -Value $response.data -Force
            return $true
        }
        else {
			Warning "Could find match phrases from API"
            return $false
        }
    } catch {
		Warning "Could not call API for match phrases"
    }
	
	return $false
}

# Function to upload a line to the API
function Upload-LogToAPI {
    param (
        [string]$line,
        [int]$retryCount = 0, 
        [int]$maxRetries = 3  
    )
    
    $payload = @{
        characterName = $config.characterName
        log           = @($line)
    } | ConvertTo-Json -Depth 2 -Compress

    try {
        # Send the line to the API
		$headers  = Build-Headers
        $response = Invoke-RestMethod -Uri "$($config.apiBaseUrl)/log/process" -Method POST -Headers $headers -Body $payload
		
		return $true
    } catch {
        if ($_.Exception.Response.StatusCode -eq 401) {
            Warning "Authentication failed. Attempting to re-authenticate..."
			Start-Sleep -Milliseconds 1000
            if (Authenticate-API) {
                Notice "Re-authentication successful. Retrying upload..."
                if ($retryCount -lt $maxRetries) {
                    Upload-LogToAPI -line $line -retryCount ($retryCount + 1) -maxRetries $maxRetries
                } else {
                    Fatal "Maximum retry attempts reached. Exiting..."                   
                }
            } else {
                Fatal "Re-authentication failed. Exiting..."
            }
        } else {
            Warning "While uploading line: $line ($($_.Exception.Message))"
        }
    }
	
	return $false
}

function ReadJsonFile {
    param (
        [string]$FilePath
    )

    $config = $null
	
	if (-not (Test-Path $FilePath)) {
		return $config
	}

    try {
        $jsonContent = Get-Content -Path $FilePath -Raw
        $config      = $jsonContent | ConvertFrom-Json
    } 
    catch {
        Warning "Failed to parse JSON file '$FilePath': Error: $($_.Exception.Message)"
    }

    return $config
}


###
### MAIN
###

if ($LogDir -and -not (Test-Path $LogDir -PathType Container)) {
    Warning "Log directory '$LogDir' does not exist."
    $logDir = $null
}

$ConfigFilePath      = $(Join-Path -Path $PSScriptRoot -ChildPath $ConfigFile)
$CredentialsFilePath = $(Join-Path -Path $PSScriptRoot -ChildPath 'credentials.json')

# Configuration
$config = ReadJsonFile -FilePath $ConfigFilePath

if ($config -eq $null -or $Init) {
    # Create a new configuration file
    while (-not $LogDir -or -not (Test-Path $LogDir -PathType Container)) {
        $LogDir = Read-Host -Prompt "Enter the full path to the log directory"
        if (-not (Test-Path $LogDir -PathType Container)) {
            Warning "The provided path '$LogDir' is not a valid directory. Please try again."
            $LogDir = $null
        }
    }

    $config = @{
        #apiBaseUrl = "http://thjinfo-dev.home.lan/api/v1"
        apiBaseUrl = "https://thj.bytelligence.com/api/v1"
        apiHeaders = @{
            "Content-Type" = "application/json"
            "Accept"       = "application/json"
        }
        logDir = $LogDir
    }

    $config | ConvertTo-Json -Depth 2 | Set-Content -Path $ConfigFilePath

    if (-not (Test-Path $CredentialsFilePath)) {
        $credentials = @{
            client_id     = ""
            client_secret = ""
        }
        $credentials | ConvertTo-Json -Depth 2 | Set-Content -Path $CredentialsFilePath
    }
} 
else {
    $config = ReadJsonFile -FilePath $ConfigFilePath

    if ($config -eq $null) {
        Fatal "Configuration file '$ConfigFilePath' is not valid or does not exist."
    }

    $credentials = ReadJsonFile -FilePath $CredentialsFilePath

    if ($credentials -eq $null) {
        Fatal "Credentials file '$CredentialsFilePath' is not valid or does not exist."
    }
}

if ($CharacterName) {
    # Look for a file matching the CharacterName
    $characterFile = Get-ChildItem -Path $config.logDir -File | Where-Object { $_.Name -match "_$($CharacterName)_" } | Select-Object -First 1

    if ($characterFile) {
		Add-Member -InputObject $config -MemberType NoteProperty -Name 'playerLogFile' -Value $characterFile.FullName -Force     
        Add-Member -InputObject $config -MemberType NoteProperty -Name 'characterName' -Value $CharacterName -Force   
    } 
}
else {
    # Default to the latest file if CharacterName is not specified
    $latestFile = Get-ChildItem -Path $config.logDir -File | Where-Object { $_.Name -match "eqlog_" } | Sort-Object LastWriteTime -Descending | Select-Object -First 1

    if ($latestFile) {
        Add-Member -InputObject $config -MemberType NoteProperty -Name 'playerLogFile' -Value $latestFile.FullName -Force

        if ($latestFile.Name -match "^eqlog_(.+?)_thj\.txt$") {
            $CharacterName = $matches[1]
            Add-Member -InputObject $config -MemberType NoteProperty -Name 'characterName' -Value $CharacterName -Force   
        } 
    } 
    else {
        Fatal "No log files found in the directory '$LogDir'."
    }
}

### Make sure we have a valid log file
if (-not $config.playerLogFile) {
    Fatal "Player log file is not set in the configuration."
}
if (-not (Test-Path $config.playerLogFile)) {
    Fatal "Player log file doe'$($config.playerLogFile)' does not exist."
}

### Authenticate to the API
Notice "Authenticating to API"
if (Authenticate-API) {
    Notice "Authentication successful"
} else {
    Fatal "Authentication failed"
}

### Download match phrases
Notice "Downloading current match phrases"
if (Download-MatchPhrases) {
    Notice "Match phrases downloaded successfully ($($config.matchPhrases.Count) available)"
} else {
    Fatal "Could not retrieve match phrases from API"
}
$matchPhrases = $($config.matchPhrases -join '|')


Notice "Monitoring log file for character '$($config.characterName)': $($config.playerLogFile)"

# Tail the file and process new lines
Get-Content -Path $config.playerLogFile -Wait -Tail 0 | ForEach-Object {
    # Skip empty lines or process as needed
    if (-not [string]::IsNullOrWhiteSpace($_)) {
		$line = $_

		if ($line -match "] LOADING, PLEASE WAIT...") {
            $lockFile = "$($config.controlDir)\\lock.txt"

			if (-not (Test-Path $lockFile)) {
				Warning "Death/zoning detected, creating lock file"
				New-Item -ItemType File -Path $lockFile | Out-Null
			}
		}
		
		if ($line -match "] ($matchPhrases)") {
			$success = Upload-LogToAPI -line $line
			if ($success) {
				Notice "Processed: $line"
			}
		}
    }
}

