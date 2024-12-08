jQuery(document).ready(function($) {
    let monacoEditor = null;
    let currentSelectedFile = null;
    let editorInitialized = false;

    // Debug logging function
    function debug(message, data = null) {
        console.log(`[QPM Debug] ${message}`, data || '');
    }

    // Show notification
    function showNotification(message, type = 'success') {
        const $notification = $('<div>')
            .addClass(`qpm-${type}`)
            .text(message)
            .hide();

        $('#qpm-editor-wrapper').before($notification);
        $notification.fadeIn().delay(3000).fadeOut(function() {
            $(this).remove();
        });
    }

    // Initialize layout
    function initializeLayout() {
        debug('Initializing layout');
        const $container = $('#qpm-editor-wrapper');
        
        // Create grid layout structure
        $container.addClass('qpm-editor-container');
        $('#qpm-file-browser').addClass('qpm-file-browser');
        
        // Create actions container
        if (!$('.qpm-editor-actions').length) {
            const $actions = $('<div>').addClass('qpm-editor-actions');
            $actions.append($('#qpm-save-patch'), $('#qpm-restore-file'));
            $('#qpm-file-editor').append($actions);
        }
    }

    // Load Monaco Editor
    function loadMonacoEditor() {
        debug('Loading Monaco Editor');
        if (editorInitialized) {
            debug('Monaco Editor already initialized');
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            if (typeof monaco !== 'undefined') {
                debug('Monaco already loaded, initializing editor');
                initMonacoEditor();
                resolve();
                return;
            }

            debug('Loading Monaco from CDN');
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.34.0/min/vs/loader.min.js';
            script.onload = () => {
                require.config({ paths: { 'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.34.0/min/vs' }});
                require(['vs/editor/editor.main'], () => {
                    debug('Monaco loaded, initializing editor');
                    initMonacoEditor();
                    resolve();
                });
            };
            script.onerror = (error) => {
                debug('Error loading Monaco:', error);
                reject(error);
            };
            document.head.appendChild(script);
        });
    }

    // Initialize Monaco Editor
    function initMonacoEditor() {
        if (!editorInitialized) {
            debug('Creating Monaco Editor instance');
            const editorContainer = document.getElementById('qpm-file-content');
            
            // Define VS Code-like theme
            monaco.editor.defineTheme('vscode-dark', {
                base: 'vs-dark',
                inherit: true,
                rules: [],
                colors: {
                    'editor.background': '#1e1e1e',
                    'editor.foreground': '#d4d4d4',
                    'editorLineNumber.foreground': '#858585',
                    'editorLineNumber.activeForeground': '#c6c6c6',
                    'editor.selectionBackground': '#264f78',
                    'editor.inactiveSelectionBackground': '#3a3d41'
                }
            });

            monacoEditor = monaco.editor.create(editorContainer, {
                value: '',
                language: 'php',
                theme: 'vscode-dark',
                automaticLayout: true,
                minimap: { enabled: true },
                scrollBeyondLastLine: false,
                renderLineHighlight: 'all',
                lineNumbers: 'on',
                roundedSelection: false,
                selectOnLineNumbers: true,
                fontSize: 13,
                fontFamily: 'Consolas, "Courier New", monospace',
                wordWrap: 'on'
            });

            editorInitialized = true;
            debug('Monaco Editor initialized');
        }
    }

    // Render file tree
    function renderFileTree(structure) {
        debug('Rendering file tree', structure);
        const $treeContent = $('#qpm-file-tree-content');
        $treeContent.empty();

        function createTreeNode(item) {
            const $node = $('<div>').addClass('qpm-file-tree-item');
            
            const $icon = $('<span>').addClass('qpm-file-tree-item-icon');
            const $name = $('<span>').addClass('qpm-file-tree-item-name').text(item.name);

            if (item.type === 'directory') {
                $node.addClass('qpm-file-tree-item-directory');
                $node.attr('data-type', 'directory');

                $node.on('click', function(e) {
                    e.stopPropagation();
                    $(this).toggleClass('expanded');
                });
            } else {
                $node.addClass('qpm-file-tree-item-file');
                $node.attr('data-type', 'file');
                $node.attr('data-path', item.relative_path);
                $node.attr('data-ext', item.extension);

                $node.on('click', function(e) {
                    e.stopPropagation();
                    $('.qpm-file-tree-item').removeClass('selected');
                    $(this).addClass('selected');
                    selectFile(item);
                });
            }

            $node.append($icon, $name);

            if (item.type === 'directory' && item.children && item.children.length) {
                const $children = $('<div>').addClass('qpm-file-tree-children');
                item.children.forEach(child => {
                    $children.append(createTreeNode(child));
                });
                $node.append($children);
            }

            return $node;
        }

        $treeContent.append(createTreeNode(structure));
        debug('File tree rendered');
    }

    // File selection handler
    async function selectFile(file) {
        debug('Selecting file', file);
        const pluginPath = $('#qpm-plugin-select').val();

        try {
            // Ensure Monaco Editor is loaded before proceeding
            await loadMonacoEditor();

            debug('Loading file content via AJAX');
            // AJAX call to load file content
            const response = await $.ajax({
                url: qpmAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qpm_load_file_content',
                    nonce: qpmAjax.nonce,
                    plugin_path: pluginPath,
                    file_path: file.relative_path
                }
            });

            if (response.success) {
                debug('File content loaded successfully');
                // Show file editor
                $('#qpm-file-editor').show();

                // Update Monaco Editor with content
                if (monacoEditor) {
                    monacoEditor.setValue(response.data.content);

                    // Set language based on file extension
                    const fileExtension = file.extension;
                    const languageMap = {
                        'php': 'php',
                        'js': 'javascript',
                        'css': 'css',
                        'json': 'json',
                        'html': 'html',
                        'htm': 'html',
                        'xml': 'xml',
                        'txt': 'plaintext',
                        'md': 'markdown'
                    };
                    const language = languageMap[fileExtension] || 'plaintext';
                    monaco.editor.setModelLanguage(monacoEditor.getModel(), language);
                }

                // Store current file details
                currentSelectedFile = {
                    path: response.data.path,
                    relative_path: response.data.relative_path,
                    plugin_name: response.data.plugin_name
                };

                // Load patch history
                loadPatchHistory(response.data.plugin_name);
            } else {
                debug('Error loading file content:', response.data);
                showNotification(response.data, 'error');
            }
        } catch (error) {
            debug('Error in selectFile:', error);
            showNotification('Error loading file content', 'error');
        }
    }

    // Plugin selection handler
    $('#qpm-plugin-select').on('change', async function() {
        const pluginPath = $(this).val();
        debug('Plugin selected:', pluginPath);
        
        if (!pluginPath) {
            $('#qpm-editor-wrapper, #qpm-patch-history').hide();
            return;
        }

        try {
            debug('Loading plugin files via AJAX');
            const response = await $.ajax({
                url: qpmAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qpm_get_plugin_files',
                    nonce: qpmAjax.nonce,
                    plugin_path: pluginPath
                }
            });

            debug('AJAX response:', response);

            if (response.success) {
                // Show editor wrapper and render file tree
                $('#qpm-editor-wrapper').show();
                renderFileTree(response.data);
                debug('Plugin files loaded and rendered');
            } else {
                debug('Error loading plugin files:', response.data);
                showNotification(response.data, 'error');
            }
        } catch (error) {
            debug('Error in plugin selection:', error);
            showNotification('Error loading plugin files', 'error');
        }
    });

    // Save patch handler
    $('#qpm-save-patch').on('click', async function() {
        if (!currentSelectedFile) {
            showNotification('Please select a file to patch', 'error');
            return;
        }

        const fileContent = monacoEditor ? monacoEditor.getValue() : '';

        try {
            debug('Saving patch');
            const response = await $.ajax({
                url: qpmAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qpm_save_file_patch',
                    nonce: qpmAjax.nonce,
                    file_path: currentSelectedFile.path,
                    file_content: fileContent,
                    plugin_name: currentSelectedFile.plugin_name
                }
            });

            if (response.success) {
                showNotification('Patch saved successfully');
                loadPatchHistory(currentSelectedFile.plugin_name);
                debug('Patch saved successfully');
            } else {
                debug('Error saving patch:', response.data);
                showNotification(response.data, 'error');
            }
        } catch (error) {
            debug('Error in savePatch:', error);
            showNotification('Error saving patch', 'error');
        }
    });

    // Restore file handler
    $('#qpm-restore-file').on('click', async function() {
        if (!currentSelectedFile) {
            showNotification('Please select a file to restore', 'error');
            return;
        }

        if (!confirm(qpmTranslations.confirm_restore)) {
            return;
        }

        try {
            debug('Restoring file');
            const response = await $.ajax({
                url: qpmAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qpm_restore_file',
                    nonce: qpmAjax.nonce,
                    file_path: currentSelectedFile.path,
                    plugin_name: currentSelectedFile.plugin_name
                }
            });

            if (response.success) {
                showNotification('File restored successfully');
                await selectFile(currentSelectedFile);
                debug('File restored successfully');
            } else {
                debug('Error restoring file:', response.data);
                showNotification(response.data, 'error');
            }
        } catch (error) {
            debug('Error in restoreFile:', error);
            showNotification('Error restoring file', 'error');
        }
    });

    // Load patch history
    async function loadPatchHistory(pluginName) {
        try {
            debug('Loading patch history');
            const response = await $.ajax({
                url: qpmAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qpm_get_patch_history',
                    nonce: qpmAjax.nonce,
                    plugin_name: pluginName
                }
            });

            if (response.success) {
                const $historyTable = $('#qpm-history-content');
                $historyTable.empty();

                if (response.data.length === 0) {
                    $historyTable.append('<tr><td colspan="4" class="qpm-no-history">' + qpmTranslations.no_history + '</td></tr>');
                } else {
                    response.data.forEach(function(entry, index) {
                        $historyTable.append(`
                            <tr class="${index % 2 === 0 ? 'qpm-history-even' : 'qpm-history-odd'}">
                                <td class="qpm-history-date">${entry.date}</td>
                                <td class="qpm-history-file">${entry.file}</td>
                                <td class="qpm-history-action">${entry.action}</td>
                                <td class="qpm-history-user">${entry.user}</td>
                            </tr>
                        `);
                    });
                }

                $('#qpm-patch-history').show();
                debug('Patch history loaded');
            } else {
                debug('Error loading patch history:', response.data);
            }
        } catch (error) {
            debug('Error in loadPatchHistory:', error);
        }
    }

    // Initialize layout on page load
    debug('Document ready, initializing');
    initializeLayout();
});
