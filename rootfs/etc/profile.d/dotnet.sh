# .NET 10 environment configuration for Coyote Linux
#
# Redirects all writable .NET paths to tmpfs at /var/cache/dotnet
# This is required because the root filesystem is immutable (squashfs)

# NuGet package cache location
export NUGET_PACKAGES="/var/cache/dotnet/nuget"

# .NET CLI home (tools, sentinels, workloads)
export DOTNET_CLI_HOME="/var/cache/dotnet/cli"

# Temp directory for build artifacts
export TMPDIR="/var/tmp"

# Disable telemetry (reduces disk writes and network traffic)
export DOTNET_CLI_TELEMETRY_OPTOUT=1

# Suppress logo and first-run experience messages
export DOTNET_NOLOGO=1
export DOTNET_SKIP_FIRST_TIME_EXPERIENCE=1

# Add .NET tools to PATH (if any are installed at runtime)
export PATH="$PATH:/var/cache/dotnet/cli/.dotnet/tools"
