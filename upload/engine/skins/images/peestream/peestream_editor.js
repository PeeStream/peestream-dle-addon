/**
 * PeeStream Video Player & Tools - DLE Editor Integration
 * File: engine/skins/images/peestream/peestream_editor.js
 * Version: 1.0.0
 */

(function() {
    window.peestreamOpenModal = function() {
        var overlay = document.getElementById('peestream-modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'peestream-modal-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.65);backdrop-filter:blur(3px);z-index:999999;display:flex;align-items:center;justify-content:center;padding:15px;box-sizing:border-box;';
            
            var box = document.createElement('div');
            box.id = 'peestream-modal-box';
            box.style.cssText = 'background:#ffffff;border-radius:10px;width:100%;max-width:680px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.25), 0 10px 10px -5px rgba(0,0,0,0.1);overflow:hidden;position:relative;animation:pstPop 0.2s ease-out;';
            
            var head = document.createElement('div');
            head.style.cssText = 'background:#0f172a;color:#ffffff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #1e293b;';
            head.innerHTML = '<div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;">' +
                '<span style="background:#0284c7;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:800;letter-spacing:0.5px;">PEESTREAM</span> ' +
                '<span>Ubaci video u vijest</span></div>' +
                '<button type="button" onclick="peestreamCloseModal()" style="background:none;border:none;color:#94a3b8;font-size:22px;line-height:1;cursor:pointer;outline:none;">&times;</button>';
            
            var body = document.createElement('div');
            body.id = 'peestream-modal-body';
            body.style.cssText = 'padding:18px 22px;max-height:80vh;overflow-y:auto;';
            body.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;"><i class="fa fa-spinner fa-spin fa-2x"></i><div style="margin-top:10px;font-weight:600;">Učitavanje PeeStream modula...</div></div>';
            
            box.appendChild(head);
            box.appendChild(body);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) peestreamCloseModal();
            });
        } else {
            overlay.style.display = 'flex';
        }
        
        // Učitaj AJAX sadržaj
        fetch('engine/ajax/peestream_modal.php?action=ui')
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var body = document.getElementById('peestream-modal-body');
                if (body) {
                    body.innerHTML = html;
                    // Ponovo izvrši skripte iz učitanog HTML-a
                    var scripts = body.getElementsByTagName('script');
                    for (var i = 0; i < scripts.length; i++) {
                        var s = document.createElement('script');
                        s.text = scripts[i].text;
                        document.body.appendChild(s);
                    }
                }
            })
            .catch(function(err) {
                var body = document.getElementById('peestream-modal-body');
                if (body) body.innerHTML = '<div style="color:#ef4444;padding:20px;text-align:center;">Nije uspjelo učitavanje modala: ' + err + '</div>';
            });
    };

    window.peestreamCloseModal = function() {
        var overlay = document.getElementById('peestream-modal-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    };

    window.peestreamInsertIntoEditor = function(code) {
        var tag = '[peestream]' + code + '[/peestream]';
        
        // 1. Froala Editor (DLE 13 - 17)
        if (typeof window.$ !== 'undefined') {
            var $short = window.$('#short_story');
            if ($short.length && typeof $short.froalaEditor === 'function') {
                try {
                    $short.froalaEditor('html.insert', tag);
                    return;
                } catch(e) {}
            }
        }
        
        // 2. TinyMCE Editor
        if (typeof window.tinyMCE !== 'undefined' && window.tinyMCE.activeEditor) {
            try {
                window.tinyMCE.activeEditor.execCommand('mceInsertContent', false, tag);
                return;
            } catch(e) {}
        }
        
        // 3. Klasični BBCode / Textarea
        var targetArea = document.getElementById('short_story') || document.getElementById('full_story');
        if (targetArea) {
            if (typeof window.insert_font === 'function') {
                window.insert_font(tag, targetArea);
            } else if (typeof targetArea.selectionStart === 'number') {
                var start = targetArea.selectionStart;
                var end = targetArea.selectionEnd;
                targetArea.value = targetArea.value.substring(0, start) + tag + targetArea.value.substring(end);
                targetArea.selectionStart = targetArea.selectionEnd = start + tag.length;
                targetArea.focus();
            } else {
                targetArea.value += "\n" + tag;
            }
        }
    };

    // Automatsko dodavanje dugmeta na DLE alatnu traku
    function peestreamInjectButton() {
        if (document.getElementById('pst_editor_btn')) return;
        
        // Pokušaj pronalaženja BBCode trake sa alatima
        var bbcodes = document.querySelector('.bb-pane') || document.querySelector('.bb-codes') || document.querySelector('#b_bbcodes');
        if (bbcodes) {
            var btn = document.createElement('div');
            btn.id = 'pst_editor_btn';
            btn.className = 'bb-btn';
            btn.style.cssText = 'display:inline-flex;align-items:center;cursor:pointer;padding:3px 8px;margin:2px;background:#0284c7;color:#fff;border-radius:4px;font-weight:700;font-size:11px;';
            btn.innerHTML = '<i class="fa fa-play" style="margin-right:4px;"></i> PeeStream';
            btn.title = 'Ubaci PeeStream video';
            btn.onclick = window.peestreamOpenModal;
            bbcodes.appendChild(btn);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', peestreamInjectButton);
    } else {
        peestreamInjectButton();
    }
    setTimeout(peestreamInjectButton, 1500); // Ponovni pokušaj za editore koji se dinamički inicijalizuju
})();
