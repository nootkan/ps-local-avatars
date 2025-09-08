(function($){
    function initAvatarUI(){
        var $btnUpload = $('.psla-upload-btn');
        var $btnRemove = $('.psla-remove-btn');  // Fixed: removed extra "var"
        var $preview   = $('#psla-avatar-preview');
        var $hidden    = $('#psla-avatar-id');

        if ($btnUpload.length) {
            var frame = null;
            $btnUpload.on('click', function(e){
                e.preventDefault();
                if (typeof wp === 'undefined' || !wp.media) return;
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: 'Select your avatar',
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var attachment = frame.state().get('selection').first().toJSON();
                    var imageUrl = (attachment.sizes && attachment.sizes.psla_avatar_small)
                        ? attachment.sizes.psla_avatar_small.url
                        : (attachment.sizes && attachment.sizes.psla_avatar ? attachment.sizes.psla_avatar.url :
                           (attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url));

                    $hidden.val(attachment.id);
                    $preview.attr('src', imageUrl);
                    
                    $preview.css('opacity', 1);
                });
                frame.open();
            });
        }

        // Fixed: Added proper remove button handler
        $btnRemove.on('click', function(e){
            e.preventDefault();
            $hidden.val('');
            
            // Use gravatar URL as fallback when removing
            var gravatarSrc = $preview.attr('data-gravatar-src');
            if (gravatarSrc) {
                $preview.attr('src', gravatarSrc);
            } else {
                $preview.attr('src', $preview.attr('data-default-src') || $preview.attr('src'));
            }
            $preview.css('opacity', 1);
        });

        $('input[type="file"][name="psla_avatar_file"]').on('change', function(){
            var input = this;
            if (input.files && input.files.length) {
                var file = input.files[0];
                

                if (window.PSLA && Array.isArray(PSLA.mimes) && PSLA.mimes.length && file.type && PSLA.mimes.indexOf(file.type) === -1) {
                    alert('Please choose a JPG, PNG, GIF, or WEBP image.');
                    input.value = '';
                    return;
                }
                if (window.PSLA && PSLA.maxKB && file.size > PSLA.maxKB * 1024) {
                    alert('Selected file exceeds the maximum size of ' + PSLA.maxKB + ' KB.');
                    input.value = '';
                    return;
                }

                var useObjectURL = !!(window.URL || window.webkitURL);
                var reader, objectUrl;

                function setPreview(src) {
                    try {
                        $preview.one('load', function(){
                            if (useObjectURL && objectUrl) { (window.URL || window.webkitURL).revokeObjectURL(objectUrl); }
                        });
                    } catch(e){}
                    $preview.attr('src', src).css('opacity', 1);
                }

                function checkDimsAndPreview(src, width, height) {
                    if (window.PSLA && ((PSLA.maxW && width > PSLA.maxW) || (PSLA.maxH && height > PSLA.maxH))) {
                        var msg = 'This image is ' + width + 'x' + height + ' px which exceeds the site max of ' + PSLA.maxW + 'x' + PSLA.maxH + ' px. It will be downscaled on save. Continue?';
                        if (!confirm(msg)) {
                            input.value = '';
                            $preview.attr('src', $preview.attr('data-default-src') || $preview.attr('src')).css('opacity', 1);
                            if (useObjectURL && objectUrl) { (window.URL || window.webkitURL).revokeObjectURL(objectUrl); }
                            return;
                        }
                    }
                    setPreview(src);
                }

                if (useObjectURL) {
                    objectUrl = (window.URL || window.webkitURL).createObjectURL(file);
                    var probe = new Image();
                    probe.onload = function(){
                        checkDimsAndPreview(objectUrl, probe.naturalWidth || probe.width, probe.naturalHeight || probe.height);
                    };
                    probe.onerror = function(){ setPreview(objectUrl); };
                    probe.src = objectUrl;
                } else {
                    reader = new FileReader();
                    reader.onload = function(e) {
                        var src = e.target.result;
                        var probe = new Image();
                        probe.onload = function(){
                            checkDimsAndPreview(src, probe.naturalWidth || probe.width, probe.naturalHeight || probe.height);
                        };
                        probe.onerror = function(){ setPreview(src); };
                        probe.src = src;
                    };
                    reader.readAsDataURL(file);
                }
            }
        });
    }
    $(document).ready(initAvatarUI);
})(jQuery);