<tr>
    <td align="center" bgcolor="{{ $banner_color }}" style="padding:25px 35px 27px;background:{{ $banner_color }}">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 18px">
            <tr>
                <td align="center" bgcolor="#ffffff" style="padding:9px 16px;background:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);border-radius:10px">
                    @if($company_website)<a href="{{ $company_website }}" style="display:block;text-decoration:none">@endif
                        @if($brand_logo_path && is_file($brand_logo_path))
                            <img src="{{ $message->embed($brand_logo_path) }}" width="150" alt="{{ $brand_logo_alt }}" style="display:block;width:150px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none">
                        @else
                            <span style="font-size:32px;font-weight:900;letter-spacing:-1px;color:#1c55a5">C<span style="color:#f17a28">D</span><span style="color:#52b34b">N</span></span>
                        @endif
                    @if($company_website)</a>@endif
                </td>
            </tr>
        </table>
        <h1 style="margin:0;color:#ffffff;font-size:24px;line-height:1.4;text-align:center">{{ $title }}</h1>
        @if($subtitle)
            <p style="margin:8px 0 0;color:{{ $subtitle_color }};font-size:15px;line-height:1.5;text-align:center">{{ $subtitle }}</p>
        @endif
    </td>
</tr>
<tr>
    <td style="padding:0;background:#ffffff">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
            <tr>
                <td width="34%" height="4" bgcolor="#1c55a5" style="height:4px;line-height:4px;font-size:0">&nbsp;</td>
                <td width="33%" height="4" bgcolor="#f17a28" style="height:4px;line-height:4px;font-size:0">&nbsp;</td>
                <td width="33%" height="4" bgcolor="#52b34b" style="height:4px;line-height:4px;font-size:0">&nbsp;</td>
            </tr>
        </table>
    </td>
</tr>
