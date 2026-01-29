<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ακύρωση Αποστολής</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #dc3545, #c82333); padding: 20px; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 24px;">
            ⚠️ Ακύρωση Αποστολής
        </h1>
    </div>
    
    <div style="background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-top: none;">
        <p>Αγαπητέ/ή <strong>{{ $volunteer->name }}</strong>,</p>
        
        <p>Λυπούμαστε που σας ενημερώνουμε ότι η παρακάτω αποστολή έχει <strong style="color: #dc3545;">ακυρωθεί</strong>:</p>
        
        <div style="background: white; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
            <h2 style="margin: 0 0 10px; color: #dc3545; text-decoration: line-through;">{{ $mission->title }}</h2>
            
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
            </table>
        </div>
        
        @if($reason)
        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
            <strong>Λόγος Ακύρωσης:</strong>
            <p style="margin: 10px 0 0;">{{ $reason }}</p>
        </div>
        @endif
        
        <p>Ζητούμε συγγνώμη για την αναστάτωση. Ελπίζουμε να σας δούμε σε επόμενες αποστολές!</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ url('/missions') }}" 
               style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
                Δείτε Διαθέσιμες Αποστολές
            </a>
        </div>
        
        <p style="color: #666; font-size: 14px; margin-top: 30px;">
            Με εκτίμηση,<br>
            Η Ομάδα του VolunteerOps
        </p>
    </div>
    
    <div style="text-align: center; padding: 15px; color: #999; font-size: 12px;">
        <p>Λαμβάνετε αυτό το email επειδή είχατε δηλώσει συμμετοχή στην αποστολή.</p>
    </div>
</body>
</html>
