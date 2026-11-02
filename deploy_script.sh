#!/bin/bash

# Script to sync this Git repository with the WordPress.org SVN repo, handling
# SVN tags, trunk, and assets. Provides versioning and asks for confirmation
# before the key (irreversible) actions.
#
# Like RocketCart, Voris keeps the plugin at the repo root and describes what
# ships via `.distignore`, with the WordPress.org listing assets (banner/icon/
# screenshots) under `.wordpress-org/`. So the sync source is the cloned repo
# root, filtered by `.distignore`, and the assets source is `.wordpress-org/`.
#
# Unlike RocketCart, Voris has NO build step and NO Composer/npm dependencies —
# everything it ships is plain PHP plus static assets and the .pot. The build
# step below is therefore a no-op unless a composer.json ever appears.

# Variables
FULL_PATH="$(pwd)"                                  # Current working directory (repo root)
SVN_DIR="$FULL_PATH/svn-temp"                       # SVN checkout scratch dir
GIT_DIR="$FULL_PATH/git-temp"                        # Git clone scratch dir
GIT_ASSETS_DIR="$GIT_DIR/.wordpress-org"            # WordPress.org listing assets in the clone
DISTIGNORE_TMP="$FULL_PATH/.distignore.rsync"       # Comment-stripped .distignore for rsync
GIT_REPO_URL="git@github.com:Rocket-Venture-Labs/voris.git" # Git repository URL
SVN_REPO_URL="https://plugins.svn.wordpress.org/voris-privacy-first-analytics" # WordPress.org SVN repo URL (exists only AFTER the slug is approved)
SVN_USERNAME="rocketventurelabs"                    # WordPress.org committer username
VERBOSE=1  # Verbosity level (0: titles only, 1: messages & errors, 2: detailed output)

# Function to display a message and capture user input
promptUserInput() {
    local prompt_message="$1"  # Message to display
    local user_input_var="$2"  # Variable name to store user input

    # Display the prompt message with -e to interpret newlines
    echo -e "$prompt_message"

    # Read user input and store it in the specified variable
    read "$user_input_var"
}

# Function to log messages with different verbosity levels and colorization
log() {
    local level=$1  # Level of verbosity for the message
    local message_type=$2  # Type of message (INFO, ERROR, WARNING, etc.)
    shift 2  # Shift the arguments to access the actual message

    local color_reset="\033[0m"
    local color_info="\033[0;32m"  # Green for INFO
    local color_error="\033[0;31m"  # Red for ERROR
    local color_warning="\033[0;33m"  # Yellow for WARNING

    case $message_type in
        "INFO")
            local color=$color_info
            ;;
        "ERROR")
            local color=$color_error
            ;;
        "WARNING")
            local color=$color_warning
            ;;
        *)
            local color=$color_reset  # No color for undefined message types
            ;;
    esac

    if [ $VERBOSE -ge $level ]; then
        echo -e "${color}$message_type: $@${color_reset}"
    fi
}

# Function to display script usage/help
usage() {
    echo -e "Usage: $0\n"
    echo -e "Options:"
    echo -e "  1  Release a specific version (sync to SVN tag only)"
    echo -e "  2  Release a production version (sync tag + trunk + assets)\n"
    echo -e "This script helps manage syncing between Git and SVN for WordPress plugins."
    echo -e "It syncs a specific version to an SVN tag and optionally to trunk and assets."
    exit 1
}

# Function to prompt the user for confirmation before performing an action
promptUserConfirmation() {
  local action_description="$1"
  local CONT='n'

  promptUserInput "You are about to $action_description. Do you wish to proceed? [y/N]:" CONT

  case $CONT in
    Y* | y*)
      return 0 ;; # Return true (success) if the user confirms
    *)
      log 0 "ERROR" "Operation cancelled by the user."
      return 1 ;; # Return false (failure) if the user cancels
  esac
}

# Function to run on error (non-zero exit code)
onError() {
    log 0 "ERROR" "Script terminated unexpectedly due to an error."
}

# Function to run on successful exit (exit code 0)
onSuccess() {
    if [ $? -eq 0 ]; then
        log 0 "INFO" "Script completed successfully."
    fi
}

# Function to run commands and handle errors
runCommand() {
    local cmd=$1
    log 1 "INFO" "Running: $cmd"
    eval "$cmd"
    if [ $? -ne 0 ]; then
        log 0 "ERROR" "Command failed: $cmd"
        exit 1
    fi
}

# Build the rsync exclude arguments from `.distignore` (single source of truth
# for what ships), plus a blanket dotfile exclude so meta dirs like `.git`,
# `.github`, and `.wordpress-org` never leak into trunk/tags.
buildRsyncExcludes() {
    RSYNC_EXCLUDES=("--exclude=.*" "--exclude=deploy_script.sh")

    if [ -f "$GIT_DIR/.distignore" ]; then
        # Strip comments and blank lines so rsync gets clean patterns.
        grep -vE '^[[:space:]]*#|^[[:space:]]*$' "$GIT_DIR/.distignore" > "$DISTIGNORE_TMP"
        RSYNC_EXCLUDES+=("--exclude-from=$DISTIGNORE_TMP")
    else
        log 1 "WARNING" "No .distignore found in the clone; syncing with dotfile excludes only."
    fi
}

# Function to remove old SVN directory and set up the new SVN working copy
setupSVN() {
    log 0 "INFO" "Setting up SVN directory..."

    # Remove old SVN directory if it exists
    if [ -d "$SVN_DIR" ]; then
        log 0 "INFO" "Removing existing SVN directory at $SVN_DIR..."
        rm -rf "$SVN_DIR"
        log 1 "INFO" "Old SVN directory removed."
    fi

    # Check if SVN is installed
    if ! svn --version &>/dev/null; then
        log 0 "ERROR" "SVN (Subversion) is not installed or not in the PATH."
        exit 1
    fi

    # Checkout the SVN repository into the new directory
    log 0 "INFO" "Checking out SVN repository from $SVN_REPO_URL to $SVN_DIR..."
    runCommand "svn checkout $SVN_REPO_URL $SVN_DIR"
    log 1 "INFO" "SVN repository checked out successfully."
}

# Pull from Git repository into temp folder
cloneRepo() {
    # Check if the Git directory exists and remove it
    if [ -d "$GIT_DIR" ]; then
        log 0 "INFO" "Removing existing Git directory at $GIT_DIR..."
        rm -rf "$GIT_DIR"
        log 1 "INFO" "Old Git directory removed."
    fi

    log 0 "INFO" "Cloning repository into $GIT_DIR..."
    runCommand "git clone --depth 1 $GIT_REPO_URL $GIT_DIR"

    log 1 "INFO" "Repository cloned to $GIT_DIR"
}

# Build production dependencies. Voris has no Composer/npm dependencies and no
# build step, so this is a no-op by design — it only runs `composer install`
# if a composer.json ever appears in the clone, keeping the script reusable.
runBuildCommands() {
    log 0 "INFO" "Running build commands..."

    if [ ! -f "$GIT_DIR/composer.json" ]; then
        log 1 "INFO" "No composer.json in the clone — Voris ships plain PHP, nothing to build."
        return 0
    fi

    if ! command -v composer &>/dev/null; then
        log 0 "ERROR" "composer is not installed or not in the PATH."
        exit 1
    fi

    pushd "$GIT_DIR" >/dev/null || {
        log 0 "ERROR" "Failed to navigate to $GIT_DIR"
        exit 1
    }
    runCommand "composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist"
    popd >/dev/null

    log 1 "INFO" "Build commands completed."
}

# Function to perform SVN file synchronization for a specific version (tag)
syncFilesToSVN() {
    local version=$1

    log 0 "INFO" "Updating the $version version in SVN..."

    # Remove old version folder in SVN if it exists
    rm -rf "$SVN_DIR/tags/$version"
    mkdir -p "$SVN_DIR/tags/$version"

    # Sync plugin files (repo root, filtered by .distignore) to the SVN tag.
    # Call rsync directly (not via eval) so glob patterns like `.*` in the
    # excludes array are passed literally instead of expanding against the cwd.
    log 1 "INFO" "Running: rsync -av ${RSYNC_EXCLUDES[*]} $GIT_DIR/ $SVN_DIR/tags/$version/"
    if ! rsync -av "${RSYNC_EXCLUDES[@]}" "$GIT_DIR/" "$SVN_DIR/tags/$version/"; then
        log 0 "ERROR" "rsync to tags/$version failed."
        exit 1
    fi
    log 1 "INFO" "Files synced to SVN for version $version."
}

# Function to sync files to the SVN trunk for production releases
syncFilesToTrunk() {
    log 0 "INFO" "Syncing files to SVN trunk..."

    # Remove old trunk folder in SVN if it exists
    rm -rf "$SVN_DIR/trunk"
    mkdir -p "$SVN_DIR/trunk"

    # Sync plugin files (repo root, filtered by .distignore) to the SVN trunk.
    # Direct rsync call (not eval) — see syncFilesToSVN for why.
    log 1 "INFO" "Running: rsync -av ${RSYNC_EXCLUDES[*]} $GIT_DIR/ $SVN_DIR/trunk/"
    if ! rsync -av "${RSYNC_EXCLUDES[@]}" "$GIT_DIR/" "$SVN_DIR/trunk/"; then
        log 0 "ERROR" "rsync to trunk failed."
        exit 1
    fi
    log 1 "INFO" "Files synced to SVN trunk."
}

# Function to sync the WordPress.org listing assets for production releases
syncAssetsToProduction() {
    log 0 "INFO" "Syncing files to SVN assets..."

    if [ ! -d "$GIT_ASSETS_DIR" ]; then
        log 1 "WARNING" "No .wordpress-org directory in the clone; skipping assets sync."
        return 0
    fi

    # Remove old assets folder in SVN if it exists
    rm -rf "$SVN_DIR/assets"
    mkdir -p "$SVN_DIR/assets"

    # Sync assets from .wordpress-org to the SVN assets folder (drop dotfiles like .gitkeep)
    log 1 "INFO" "Running: rsync -av --exclude='.*' $GIT_ASSETS_DIR/ $SVN_DIR/assets/"
    if ! rsync -av --exclude='.*' "$GIT_ASSETS_DIR/" "$SVN_DIR/assets/"; then
        log 0 "ERROR" "rsync to assets failed."
        exit 1
    fi
    log 1 "INFO" "Files synced to SVN assets."
}

# Function to add and remove files in SVN
manageSVNChanges() {
    local version=$1
    pushd "$SVN_DIR" || {
        log 0 "ERROR" "Failed to navigate to $SVN_DIR"
        exit 1
    }

    # Add all files and directories under trunk, assets, and the specific version folder in tags.
    svn add --force --quiet trunk/* assets/* "tags/$version" 2>/dev/null

    # Check for deleted files and remove them from SVN if any exist
    deleted_files=$(svn status | grep '^!' | awk '{print $2}')
    if [ -n "$deleted_files" ]; then
        echo "$deleted_files" | xargs svn rm --quiet
        log 1 "INFO" "Deleted files removed from SVN."
    else
        log 1 "WARNING" "No deleted files to remove."
    fi

    # Show SVN status after changes
    svn status

    popd
}

# Function to confirm the commit
confirmCommit() {
    log 0 "INFO" "Confirming commit to SVN..."
    if ! promptUserConfirmation "proceed with the commit"; then
          if promptUserConfirmation "revert the updates"; then
            log 0 "WARNING" "Reverting the updates..."
            runCommand "svn revert -R $SVN_DIR"
            svn status
          else
              log 0 "ERROR" "Updates not reverted."
              exit 1
          fi
    fi
}

# Function to commit changes to SVN
commitToSVN() {
    local version=$1
    pushd "$SVN_DIR" || {
        log 0 "ERROR" "Failed to navigate to $SVN_DIR"
        exit 1
    }

    # Prompt for the commit message
    promptUserInput "Enter the commit message:" COMMIT_MESSAGE

    log 0 "INFO" "Committing version $version to SVN with message: $COMMIT_MESSAGE"
    runCommand "svn ci -m '$COMMIT_MESSAGE' --username $SVN_USERNAME"
    log 0 "INFO" "$version version committed to SVN."

    popd
}

# Cleanup function to remove temporary directories
cleanup() {
    log 0 "INFO" "Cleaning up..."
    if [ -d "$SVN_DIR" ]; then
        rm -rf "$SVN_DIR"
        log 1 "WARNING" "$SVN_DIR folder removed."
    fi
    if [ -d "$GIT_DIR" ]; then
        rm -rf "$GIT_DIR"
        log 1 "WARNING" "$GIT_DIR folder removed."
    fi
    if [ -f "$DISTIGNORE_TMP" ]; then
        rm -f "$DISTIGNORE_TMP"
    fi
    log 1 "INFO" "Cleanup complete."
}

# Function to ask for version and release it
releaseVersion() {
    local action_type="$1"

    setupSVN
    cloneRepo
    buildRsyncExcludes

    # Extract the stable tag version from the readme.txt file (repo root)
    stable_tag=$(grep -i "Stable tag" "$GIT_DIR/readme.txt" | awk -F': ' '{print $2}' | tr -d '[:space:]')

    promptUserInput "Enter the version to publish (e.g., 1.2.0 or beta). Current README version: $stable_tag:" VERSION

    # Compare the stable_tag and VERSION
    if [ "$stable_tag" != "$VERSION" ]; then
        log 0 "WARNING" "The version ($VERSION) you want to release does not match the stable tag in readme.txt ($stable_tag)."
        if ! promptUserConfirmation "continue with this version mismatch"; then
            log 0 "ERROR" "Version mismatch operation cancelled by the user."
            exit 1
        fi
    fi

    runBuildCommands
    syncFilesToSVN "$VERSION"
    if [ "$action_type" == '2' ]; then
          if promptUserConfirmation "sync the release to 'svn/trunk'"; then
              syncFilesToTrunk
          else
              log 0 "WARNING" "sync the release to 'svn/trunk' operation cancelled by the user."
          fi
          if promptUserConfirmation "sync the assets to 'svn/assets'"; then
              syncAssetsToProduction
          else
              log 0 "WARNING" "sync the assets to 'svn/assets' operation cancelled by the user."
          fi
    fi
    manageSVNChanges "$VERSION"
    confirmCommit
    commitToSVN "$VERSION"
    cleanup
    exit 0;
}

# Trap for errors
trap 'onError' ERR

# Trap for successful exits
trap 'onSuccess' EXIT

# Main menu
promptUserInput "Select an option:\n1. Release a specific version\n2. Release a production version" OPTION

case $OPTION in
    1|2)
      releaseVersion $OPTION
      ;;
    *)
      log 0 "ERROR" "Invalid option."
      usage  # Call the usage function to display the help message
      exit 1
      ;;
esac
