<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nouvelle demande de demo</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.45;">
  <h2>Nouvelle demande de demo recue</h2>

  <p>Une nouvelle demande a ete envoyee depuis la page presentation.</p>

  <table cellpadding="8" cellspacing="0" border="1" style="border-collapse: collapse; border-color: #ddd;">
    <tr>
      <td><strong>Nom complet</strong></td>
      <td>{{ $demoRequest->full_name }}</td>
    </tr>
    <tr>
      <td><strong>Etablissement</strong></td>
      <td>{{ $demoRequest->business_name ?: '-' }}</td>
    </tr>
    <tr>
      <td><strong>Email</strong></td>
      <td>{{ $demoRequest->email }}</td>
    </tr>
    <tr>
      <td><strong>Telephone</strong></td>
      <td>{{ $demoRequest->phone ?: '-' }}</td>
    </tr>
    <tr>
      <td><strong>Profil</strong></td>
      <td>{{ $demoRequest->profile ?: '-' }}</td>
    </tr>
    <tr>
      <td><strong>Date</strong></td>
      <td>{{ $demoRequest->created_at?->format('d/m/Y H:i') }}</td>
    </tr>
  </table>
</body>
</html>

