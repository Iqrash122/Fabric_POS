# Source this file in the current ChatGPT session; it does not change system settings.
FABRIC_RUNTIME="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/runtime/bin"
if [ ! -x "$FABRIC_RUNTIME/php" ]; then
    printf 'Local PHP runtime missing. Use the Docker workspace instructions.\n' >&2
    return 1
fi
export PATH="$FABRIC_RUNTIME:$PATH"
unset FABRIC_RUNTIME
