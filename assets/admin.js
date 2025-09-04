(function($){
    function initAvatarUI(){
        var $btnUpload = $('.psla-upload-btn');
        var $btnRemove = $('.psla-remove-btn');
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
                    $('input[name="psla_avatar_source"][value="uploaded"]').prop('checked', true);
                    $preview.css('opacity', 1);
                });
                frame.open();
            });
        }

        $btnRemove.on('click', function(e){
            e.preventDefault();
            $hidden.val('');
            $('input[name="psla_avatar_source"][value="gravatar"]').prop('checked', true);
            $preview.css('opacity', .6);
        });

        $('input[type="file"][name="psla_avatar_file"]').on('change', function(){
            if (this.files && this.files.length) {
                $('input[name="psla_avatar_source"][value="uploaded"]').prop('checked', true);
                $preview.css('opacity', 1);
            }
        });
    }
    $(document).ready(initAvatarUI);
})(jQuery);
