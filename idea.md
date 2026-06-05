W Polsce trwa ikoniczna wojna pomiędzy siecią marketów Lidl i Biedronka. Reklamy każdorazowo przekazują niejako, że sieć X jest tańsza niż Y.
Czas z tym skończyć i wreszcie odpowiedzieć na te odwieczne pytanie.

Ideą jest stworzenie prostej strony (aplikacji) www (mobile-first), która będzie się składała z:

- Części serwerowej (backend), która będzie odpowiedzialna za pobieranie, parsowanie, analizowanie na bieżąco gazetek lub innych oficjalnych źródeł na temat promocji i cen w danej sieci.
- Obszaru użytkownika (f-end), gdzie użytkownicy będą mogli porównać ceny konkretnych produktów lub koszyków.

Funkcje:

- System musi potrafić przetworzyć dowolny format, w tym także gazetkę w formie graficznej w ustrukturyzowaną bazę danych.
- Musi dobrze rozumieć charakter promocji, zwłaszcza promocji typu 1+1, drugi produkt za złotówkę.
- Docelowo ma mieć możliwość obsługi większej ilości sieci - Lidl i Biedronka to zakres MVP.
- System musi mieć bieżące dane, zakładamy odświeżanie na żądanie i automatyczne (cron) raz w nocy.
- Strona główna będzie wyświetlała tylko pojedyncze, stałe porównanie cen. Dokładny raport będzie dostępny po zalogowaniu.
- Użytkownicy będą mogli generować raport porównania całego koszyka zakupów.
- Koszyk będzie mógł być zapisany per użytkownik.


Poza MVP:
- Uwzględniamy proste porównanie (mleko X = mleko Y), zwracamy tylko uwagę na różnice w marce porównywanych produktów.
- Pod względem programistycznym uwzględniamy wiele typów źródeł (API, PDF, JPG), ale nie implementujemy jeszcze providerów.


Nazwa projektu: nieokreślona. O ile w wersji I będzie skupiać się tylko na tych dwóch sieciach, nazwa musi być bardziej uniwersalna.
