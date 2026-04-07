#!/bin/bash
# =========================================================================
# Script to create the distribution package for Global Search Enhancer
# For Linux/Mac/Git Bash
# =========================================================================

VERSION="2.2.0"
PLUGIN_NAME="globalsearch"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}==================================================${NC}"
echo -e "${CYAN}  Global Search Enhancer - Build Release${NC}"
echo -e "${CYAN}  Version: ${VERSION} (GLPI 11 Only)${NC}"
echo -e "${CYAN}==================================================${NC}"
echo ""

# Create temporary directory
TEMP_DIR="./build/${PLUGIN_NAME}"
if [ -d "./build" ]; then
    rm -rf ./build
fi
mkdir -p "${TEMP_DIR}"

echo -e "${YELLOW}[1/4] Copying files...${NC}"

# Copy directory structure and files
copy_files() {
    # Root files
    cp setup.php "${TEMP_DIR}/" && echo -e "${GREEN}  ✓ Copied: setup.php${NC}"
    cp plugin.xml "${TEMP_DIR}/" && echo -e "${GREEN}  ✓ Copied: plugin.xml${NC}"
    cp LICENSE "${TEMP_DIR}/" && echo -e "${GREEN}  ✓ Copied: LICENSE${NC}"
    cp README.md "${TEMP_DIR}/" && echo -e "${GREEN}  ✓ Copied: README.md${NC}"
    cp CHANGELOG.md "${TEMP_DIR}/" && echo -e "${GREEN}  ✓ Copied: CHANGELOG.md${NC}"
    cp hook.php "${TEMP_DIR}/" 2>/dev/null && echo -e "${GREEN}  ✓ Copied: hook.php${NC}" || echo -e "${YELLOW}  ⚠ hook.php not found (optional)${NC}"
    
    # inc/ folder
    if [ -d "inc" ]; then
        mkdir -p "${TEMP_DIR}/inc"
        if ls inc/*.php >/dev/null 2>&1; then
            cp inc/*.php "${TEMP_DIR}/inc/" && echo -e "${GREEN}  ✓ Copied: inc/*.php${NC}"
        fi
    fi
    
    # front/ folder
    if [ -d "front" ]; then
        mkdir -p "${TEMP_DIR}/front"
        if ls front/*.php >/dev/null 2>&1; then
            cp front/*.php "${TEMP_DIR}/front/" && echo -e "${GREEN}  ✓ Copied: front/*.php${NC}"
        fi
    fi
    
    # public/ folder (JS and CSS)
    if [ -d "public" ]; then
        mkdir -p "${TEMP_DIR}/public/css"
        mkdir -p "${TEMP_DIR}/public/js"
        if ls public/css/*.css >/dev/null 2>&1; then
            cp public/css/*.css "${TEMP_DIR}/public/css/" && echo -e "${GREEN}  ✓ Copied: public/css/*.css${NC}"
        fi
        if ls public/js/*.js >/dev/null 2>&1; then
            cp public/js/*.js "${TEMP_DIR}/public/js/" && echo -e "${GREEN}  ✓ Copied: public/js/*.js${NC}"
        fi
    fi
    
    # templates/ folder
    if [ -d "templates" ]; then
        mkdir -p "${TEMP_DIR}/templates"
        cp templates/*.twig "${TEMP_DIR}/templates/" && echo -e "${GREEN}  ✓ Copied: templates/*.twig${NC}"
    fi

    # locales/ folder
    if [ -d "locales" ]; then
        mkdir -p "${TEMP_DIR}/locales"
        if ls locales/*.php >/dev/null 2>&1; then
            cp locales/*.php "${TEMP_DIR}/locales/" && echo -e "${GREEN}  ✓ Copied: locales/*.php${NC}"
        fi
        if ls locales/*.mo >/dev/null 2>&1; then
            cp locales/*.mo "${TEMP_DIR}/locales/" && echo -e "${GREEN}  ✓ Copied: locales/*.mo${NC}"
        fi
        if ls locales/*.po >/dev/null 2>&1; then
            cp locales/*.po "${TEMP_DIR}/locales/" && echo -e "${GREEN}  ✓ Copied: locales/*.po${NC}"
        fi
    fi
    
    # assets/ folder
    if [ -d "assets" ]; then
        mkdir -p "${TEMP_DIR}/assets/screenshots"
        if [ -f "assets/logo.png" ]; then
            cp assets/logo.png "${TEMP_DIR}/assets/" && echo -e "${GREEN}  ✓ Copied: assets/logo.png${NC}"
        fi
        if [ -d "assets/screenshots" ] && ls assets/screenshots/* >/dev/null 2>&1; then
            cp assets/screenshots/* "${TEMP_DIR}/assets/screenshots/" && echo -e "${GREEN}  ✓ Copied: assets/screenshots/*${NC}"
        fi
    fi
}

copy_files

echo ""
echo -e "${YELLOW}[2/4] Verifying structure...${NC}"

# Verify critical files
ALL_OK=true
CRITICAL_FILES=("setup.php" "plugin.xml" "LICENSE" "README.md" "CHANGELOG.md" "hook.php" "inc/config.class.php" "inc/searchengine.class.php" "front/config.form.php" "public/css/globalsearch.css" "public/js/globalsearch_header.js" "public/js/globalsearch_enhanced.js" "templates/search_results.html.twig")

for file in "${CRITICAL_FILES[@]}"; do
    if [ -f "${TEMP_DIR}/${file}" ]; then
        echo -e "${GREEN}  ✓ ${file}${NC}"
    else
        echo -e "${RED}  ✗ ${file} - MISSING!${NC}"
        ALL_OK=false
    fi
done

if [ "$ALL_OK" = false ]; then
    echo ""
    echo -e "${RED}ERROR: Critical files are missing. Aborting.${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}[3/4] Creating ZIP file...${NC}"

# Create ZIP
cd build
if [ -f "../${ZIP_NAME}" ]; then
    rm "../${ZIP_NAME}"
fi

# Use PowerShell on Windows or zip on Linux/Mac
if command -v powershell.exe &> /dev/null; then
    powershell.exe -NoProfile -Command "Compress-Archive -Path '${PLUGIN_NAME}' -DestinationPath '../${ZIP_NAME}' -CompressionLevel Optimal" > /dev/null 2>&1
elif command -v zip &> /dev/null; then
    zip -r "../${ZIP_NAME}" "${PLUGIN_NAME}" > /dev/null 2>&1
else
    echo -e "${RED}ERROR: Neither zip nor PowerShell found${NC}"
    cd ..
    exit 1
fi
cd ..

ZIP_SIZE=$(du -h "${ZIP_NAME}" 2>/dev/null | cut -f1)
if [ -z "$ZIP_SIZE" ]; then
    ZIP_SIZE="unknown"
fi
echo -e "${GREEN}  ✓ Created: ${ZIP_NAME} (${ZIP_SIZE})${NC}"

echo ""
echo -e "${YELLOW}[4/4] Cleaning up temporary files...${NC}"
rm -rf ./build
echo -e "${GREEN}  ✓ Cleanup completed${NC}"

echo ""
echo -e "${CYAN}==================================================${NC}"
echo -e "${GREEN}  ✓ Release created successfully!${NC}"
echo -e "${CYAN}==================================================${NC}"
echo ""
echo -e "${NC}File: ${ZIP_NAME}${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo -e "${NC}  1. Go to: https://github.com/JuanCarlosAcostaPeraba/glpi-globalsearch-plugin/releases/new${NC}"
echo -e "${NC}  2. Tag: v${VERSION}${NC}"
echo -e "${NC}  3. Title: v${VERSION} - GLPI 11 Native${NC}"
echo -e "${NC}  4. Upload file: ${ZIP_NAME}${NC}"
echo -e "${NC}  5. Description: See notes in CHANGELOG.md${NC}"
echo -e "${YELLOW}     ⚠️ IMPORTANT: This version only works with GLPI 11.0.x${NC}"
echo ""
