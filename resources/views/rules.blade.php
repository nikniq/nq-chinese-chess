@extends('layouts.app')
@section('title', 'How to Play Chinese Chess')
@section('description', 'The rules of Chinese Chess (Xiangqi) on one page: the board, how each piece moves, check, checkmate and how scoring works.')
@section('content')
<article class="card prose">
    <h1>How to play Chinese Chess</h1>
    <p class="lead small">Two armies, Red and Black, face each other across a river. Red moves first. You win by trapping the enemy general so it cannot escape capture.</p>

    <h2>The board</h2>
    <p>Pieces stand on the <strong>intersections</strong> of a 9 × 10 grid, not inside the squares. The <strong>river</strong> across the middle limits elephants and changes how soldiers move. Each side has a <strong>palace</strong>: the 3 × 3 area marked with an X, which the general and advisors may never leave.</p>

    <h2 id="pieces">The pieces</h2>
    <div class="piece-table">
        @foreach ([
            ['帥', '將', 'General', 'One point along a line (not diagonally), and only inside the palace. The two generals may never face each other on the same file with nothing between them — the "flying general" rule.'],
            ['仕', '士', 'Advisor', 'One point diagonally, inside the palace only.'],
            ['相', '象', 'Elephant', 'Exactly two points diagonally. It cannot cross the river, and it is blocked if a piece sits on the point in between.'],
            ['傌', '馬', 'Horse', 'One point straight, then one diagonally outward — like a chess knight, but it is blocked if a piece sits on the first point ("hobbling the horse").'],
            ['俥', '車', 'Chariot', 'Any distance along a line, like a chess rook. The strongest piece on the board.'],
            ['炮', '砲', 'Cannon', 'Moves like a chariot, but to capture it must jump exactly one piece (of either colour) along the line.'],
            ['兵', '卒', 'Soldier', 'One point forward. After crossing the river it may also step sideways. It never moves backward.'],
        ] as [$red, $black, $name, $how])
            <div class="piece-row">
                <div class="pieces"><span class="pc red">{{ $red }}</span><span class="pc blk">{{ $black }}</span></div>
                <div><h3>{{ $name }}</h3><p>{{ $how }}</p></div>
            </div>
        @endforeach
    </div>

    <h2>Check, checkmate and other endings</h2>
    <ul>
        <li><strong>Check:</strong> your general is under attack. You must get out of it on your next move.</li>
        <li><strong>Checkmate:</strong> no move gets out of check — you lose.</li>
        <li><strong>No legal moves:</strong> unlike international chess, a player who cannot move also <em>loses</em>. There is no stalemate draw.</li>
        <li><strong>Repetition:</strong> here, the same position occurring three times is a draw, and so is a game reaching 150 moves each.</li>
    </ul>

    <h2 id="scoring">Scoring</h2>
    <p>Signed-in players earn points for ranked games against the computer:</p>
    <table class="small-table">
        <thead><tr><th>Strength</th><th>Win</th><th>Draw</th><th>Loss</th></tr></thead>
        <tbody>
            <tr><td>Easy</td><td>10</td><td>3</td><td>0</td></tr>
            <tr><td>Medium</td><td>20</td><td>6</td><td>0</td></tr>
            <tr><td>Hard</td><td>30</td><td>9</td><td>0</td></tr>
        </tbody>
    </table>
    <p>Using <strong>undo</strong> or a <strong>hint</strong> makes that game unranked. Games against a friend are never scored.</p>

    <p><a href="{{ route('play') }}" class="btn">Start a game</a></p>
</article>
@endsection
