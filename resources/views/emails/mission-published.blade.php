<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Νέα Αποστολή</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #28a745, #20c997); padding: 20px; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 24px;">
            🎯 Νέα Αποστολή Διαθέσιμη!
        </h1>
    </div>
    
    <div style="background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-top: none;">
        <p>Αγαπητέ/ή <strong>{{ $volunteer->name }}</strong>,</p>
        
        <p>Μια νέα αποστολή είναι διαθέσιμη για συμμετοχή:</p>
        
        <div style="background: white; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
            <h2 style="margin: 0 0 10px; color: #28a745;">{{ $mission->title }}</h2>
            
            @if($mission->description)
            <p style="color: #666; margin: 0 0 15px;">{{ Str::limit($mission->description, 200) }}</p>
            @endif
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px 0; color: #666;">📅 Ημερομηνία:</td>
                    <td style="padding: 5px 0;"><strong>{{ $mission->start_datetime->format('d/m/Y') }}</strong></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">🕐 Ώρες:</td>
                    <td style="padding: 5px 0;"><strong>{{ $mission->start_datetime->format('H:i') }} - {{ $mission->end_datetime->format('H:i') }}</strong></td>
                </tr>
                @if($mission->location)
                <tr>
                    <td style="padding: 5px 0; color: #666;">📍 Τοποθεσία:</td>
                    <td style="padding: 5px 0;"><strong>{{ $mission->location }}</strong></td>
                </tr>
                @endif
                @if($mission->department)
                <tr>
                    <td style="padding: 5px 0; color: #666;">🏢 Τμήμα:</td>
                    <td style="padding: 5px 0;"><strong>{{ $mission->department->name }}</strong></td>
                </tr>
                @endif
            </table>
        </div>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ url('/missions/' . $mission->id) }}" 
               style="background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
                Δήλωση Συμμετοχής
            </a>
        </div>
        
        <p style="color: #666; font-size: 14px; margin-top: 30px;">
            Με εκτίμηση,<br>
            Η Ομάδα του VolunteerOps
        </p>
    </div>
    
    <div style="text-align: center; padding: 15px; color: #999; font-size: 12px;">
        <p>Λαμβάνετε αυτό το email επειδή είστε εγγεγραμμένος εθελοντής.</p>
    </div>
</body>
</html>
